<?php
include 'config.php';

// Create public_holidays table if not exists
$createTable = "CREATE TABLE IF NOT EXISTS public_holidays (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    holiday_date DATE NOT NULL,
    day_of_week VARCHAR(20) NOT NULL,
    holiday_name VARCHAR(255) NOT NULL,
    active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)";
mysqli_query($conn, $createTable);

// Insert default 2026 Sri Lanka public holidays if table is empty
$checkEmpty = mysqli_query($conn, "SELECT COUNT(*) as cnt FROM public_holidays");
$rowCount = mysqli_fetch_assoc($checkEmpty);
if ($rowCount['cnt'] == 0) {
    $defaults = [
        ['2026-01-03', 'Saturday',  'Duruthu Full Moon Poya Day'],
        ['2026-01-15', 'Thursday',  'Tamil Thai Pongal Day'],
        ['2026-02-01', 'Sunday',    'Navam Full Moon Poya Day'],
        ['2026-02-04', 'Wednesday', 'National Day'],
        ['2026-02-15', 'Sunday',    'Mahasivarathri Day'],
        ['2026-03-02', 'Monday',    'Madin Full Moon Poya Day'],
        ['2026-03-21', 'Saturday',  'Id Ul-Fitr'],
        ['2026-04-01', 'Wednesday', 'Bak Full Moon Poya Day'],
        ['2026-04-03', 'Friday',    'Good Friday'],
        ['2026-04-13', 'Monday',    'Day prior to Sinhala & Tamil New Year Day'],
        ['2026-04-14', 'Tuesday',   'Sinhala & Tamil New Year Day'],
        ['2026-05-01', 'Friday',    'May Day'],
        ['2026-05-01', 'Friday',    'Vesak Full Moon Poya Day'],
        ['2026-05-02', 'Saturday',  'Day following Vesak Full Moon Poya Day'],
        ['2026-05-28', 'Thursday',  'Id Ul-Alha'],
        ['2026-05-30', 'Saturday',  'Adhi Poson Full Moon Poya Day'],
        ['2026-06-29', 'Monday',    'Poson Full Moon Poya Day'],
        ['2026-07-29', 'Wednesday', 'Esala Full Moon Poya Day'],
        ['2026-08-26', 'Wednesday', 'Milad un-Nabi'],
        ['2026-08-27', 'Thursday',  'Nikini Full Moon Poya Day'],
        ['2026-09-26', 'Saturday',  'Binara Full Moon Poya Day'],
        ['2026-10-25', 'Sunday',    'Vap Full Moon Poya Day'],
        ['2026-11-08', 'Sunday',    'Deepavali'],
        ['2026-11-24', 'Tuesday',   'Ill Full Moon Poya Day'],
        ['2026-12-23', 'Wednesday', 'Unduvap Full Moon Poya Day'],
        ['2026-12-25', 'Friday',    'Christmas Day'],
    ];
    foreach ($defaults as $h) {
        $d    = $h[0];
        $dow  = $h[1];
        $name = mysqli_real_escape_string($conn, $h[2]);
        mysqli_query($conn, "INSERT INTO public_holidays (holiday_date, day_of_week, holiday_name) VALUES ('$d','$dow','$name')");
    }
}

// Handle Delete
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    if (mysqli_query($conn, "DELETE FROM public_holidays WHERE id = $id")) {
        $success_message = "Holiday deleted successfully!";
    } else {
        $error_message = "Error deleting holiday: " . mysqli_error($conn);
    }
}

// Handle form submission (Create or Update)
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $holiday_date = isset($_POST['holiday_date']) ? mysqli_real_escape_string($conn, trim($_POST['holiday_date'])) : '';
    $holiday_name = isset($_POST['holiday_name']) ? mysqli_real_escape_string($conn, trim($_POST['holiday_name'])) : '';
    $active       = isset($_POST['active']) ? 1 : 0;

    $day_of_week = '';
    if (!empty($holiday_date)) {
        $day_of_week = date('l', strtotime($holiday_date));
    }

    if (empty($holiday_date) || empty($holiday_name)) {
        $error_message = "Date and holiday name are required fields.";
    } else {
        if (isset($_POST['holiday_id']) && !empty($_POST['holiday_id'])) {
            $id  = intval($_POST['holiday_id']);
            $sql = "UPDATE public_holidays SET
                        holiday_date = '$holiday_date',
                        day_of_week  = '$day_of_week',
                        holiday_name = '$holiday_name',
                        active       = '$active'
                    WHERE id = $id";
            if (mysqli_query($conn, $sql)) {
                $success_message = "Holiday updated successfully!";
            } else {
                $error_message = "Error: " . mysqli_error($conn);
            }
        } else {
            $sql = "INSERT INTO public_holidays (holiday_date, day_of_week, holiday_name, active)
                    VALUES ('$holiday_date','$day_of_week','$holiday_name','$active')";
            if (mysqli_query($conn, $sql)) {
                $success_message = "Holiday added successfully!";
            } else {
                $error_message = "Error: " . mysqli_error($conn);
            }
        }
    }
}

// Handle search
$search       = '';
$search_query = '';
if (isset($_GET['search']) && !empty(trim($_GET['search']))) {
    $search       = mysqli_real_escape_string($conn, trim($_GET['search']));
    $search_query = " WHERE holiday_name LIKE '%$search%'
                      OR day_of_week     LIKE '%$search%'
                      OR holiday_date    LIKE '%$search%'";
}

$holidays_sql    = "SELECT * FROM public_holidays $search_query ORDER BY holiday_date ASC, id ASC";
$holidays_result = mysqli_query($conn, $holidays_sql);
$total_holidays  = mysqli_num_rows($holidays_result);

include 'header.php';
?>

<div class="page-header">
    <h2 class="page-title">Public Holidays</h2>
    <p class="page-subtitle">Manage public holidays — add dates and holiday names</p>
</div>

<?php if (isset($success_message)): ?>
<div class="alert alert-success">
    <i class="fa-solid fa-circle-check"></i>
    <?php echo $success_message; ?>
</div>
<?php endif; ?>

<?php if (isset($error_message)): ?>
<div class="alert alert-error">
    <i class="fa-solid fa-circle-exclamation"></i>
    <?php echo $error_message; ?>
</div>
<?php endif; ?>

<div class="action-bar">
    <div class="search-box">
        <form method="GET" action="" id="searchForm">
            <div class="search-input-wrapper">
                <i class="fa-solid fa-search search-icon"></i>
                <input
                    type="text"
                    name="search"
                    id="searchInput"
                    class="search-input"
                    placeholder="Search holidays by name, day, or date..."
                    value="<?php echo htmlspecialchars($search); ?>"
                >
                <?php if (!empty($search)): ?>
                <button type="button" class="clear-search" onclick="clearSearch()">
                    <i class="fa-solid fa-xmark"></i>
                </button>
                <?php endif; ?>
            </div>
        </form>
        <?php if (!empty($search)): ?>
        <small class="search-results-text">
            Found <?php echo $total_holidays; ?> result<?php echo $total_holidays != 1 ? 's' : ''; ?> for "<?php echo htmlspecialchars($search); ?>"
        </small>
        <?php endif; ?>
    </div>

    <button onclick="openModal()" class="btn btn-primary">
        <i class="fa-solid fa-plus"></i>
        Add New Holiday
    </button>
</div>

<div class="content-card">
    <div class="card-header-with-count">
        <h3 class="card-title">All Public Holidays</h3>
        <span class="item-count"><?php echo $total_holidays; ?> holiday<?php echo $total_holidays != 1 ? 's' : ''; ?></span>
    </div>

    <?php if (mysqli_num_rows($holidays_result) > 0): ?>
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Date</th>
                    <th>Day</th>
                    <th>Holiday Name</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($holiday = mysqli_fetch_assoc($holidays_result)): ?>
                <tr>
                    <td><?php echo $holiday['id']; ?></td>
                    <td><strong><?php echo date('d-M-y', strtotime($holiday['holiday_date'])); ?></strong></td>
                    <td>
                        <span class="entity-tag">
                            <i class="fa-solid fa-calendar-day"></i>
                            <?php echo htmlspecialchars($holiday['day_of_week']); ?>
                        </span>
                    </td>
                    <td><?php echo htmlspecialchars($holiday['holiday_name']); ?></td>
                    <td>
                        <?php if ($holiday['active']): ?>
                            <span class="badge badge-success">
                                <i class="fa-solid fa-circle-check"></i> Active
                            </span>
                        <?php else: ?>
                            <span class="badge badge-inactive">
                                <i class="fa-solid fa-circle-xmark"></i> Inactive
                            </span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="action-buttons">
                            <a href="#"
                               onclick="editHoliday(<?php echo $holiday['id']; ?>)"
                               class="btn-action btn-edit"
                               title="Edit">
                                <i class="fa-solid fa-pen"></i>
                            </a>
                            <a href="?delete=<?php echo $holiday['id']; ?><?php echo !empty($search) ? '&search='.urlencode($search) : ''; ?>"
                               class="btn-action btn-delete"
                               title="Delete"
                               onclick="return confirm('Are you sure you want to delete this holiday?')">
                                <i class="fa-solid fa-trash"></i>
                            </a>
                        </div>
                    </td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div class="empty-state">
        <i class="fa-solid fa-calendar-xmark"></i>
        <h3>No Holidays Found</h3>
        <p>
            <?php if (!empty($search)): ?>
                No holidays match your search criteria. Try a different search term.
            <?php else: ?>
                Add your first public holiday using the "Add New Holiday" button above.
            <?php endif; ?>
        </p>
        <?php if (!empty($search)): ?>
        <button onclick="clearSearch()" class="btn btn-secondary">
            <i class="fa-solid fa-xmark"></i> Clear Search
        </button>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<!-- Add / Edit Modal -->
<div id="holidayModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title" id="modalTitle">Add New Holiday</h3>
            <button class="modal-close" onclick="closeModal()">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form method="POST" action="" id="holidayForm">
            <input type="hidden" name="holiday_id" id="holiday_id">

            <div class="modal-body">
                <div class="form-row">
                    <div class="form-group">
                        <label for="holiday_date" class="form-label">Date <span class="required">*</span></label>
                        <input type="date" name="holiday_date" id="holiday_date" class="form-input" required onchange="updateDay(this.value)">
                        <small class="form-hint">Select the holiday date</small>
                    </div>
                    <div class="form-group">
                        <label for="day_display" class="form-label">Day of Week</label>
                        <input type="text" id="day_display" class="form-input" placeholder="Auto-filled when date selected" readonly>
                        <small class="form-hint">Automatically calculated from date</small>
                    </div>
                </div>

                <div class="form-group">
                    <label for="holiday_name" class="form-label">Holiday Name <span class="required">*</span></label>
                    <input type="text" name="holiday_name" id="holiday_name" class="form-input" placeholder="e.g. Christmas Day" required>
                </div>

                <div class="form-group">
                    <label class="form-label">Status</label>
                    <div class="checkbox-wrapper">
                        <label class="switch">
                            <input type="checkbox" name="active" id="active" checked>
                            <span class="slider"></span>
                        </label>
                        <span class="switch-label">Active</span>
                    </div>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal()">
                    <i class="fa-solid fa-xmark"></i>
                    Cancel
                </button>
                <button type="submit" class="btn btn-primary">
                    <i class="fa-solid fa-check"></i>
                    <span id="submitBtnText">Save Holiday</span>
                </button>
            </div>
        </form>
    </div>
</div>

<style>
/* Alert Styles */
.alert {
    padding: 16px 20px;
    border-radius: 8px;
    margin-bottom: 24px;
    display: flex;
    align-items: center;
    gap: 12px;
    font-size: 13px;
    font-weight: 500;
    animation: slideDown 0.3s ease;
}
@keyframes slideDown {
    from { opacity: 0; transform: translateY(-10px); }
    to   { opacity: 1; transform: translateY(0); }
}
.alert i { font-size: 18px; }
.alert-success { background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; }
.alert-error   { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }

/* Action Bar */
.action-bar {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 20px;
    margin-bottom: 24px;
}
.search-box { flex: 1; max-width: 500px; }
.search-input-wrapper { position: relative; display: flex; align-items: center; }
.search-icon { position: absolute; left: 16px; color: #666666; font-size: 14px; pointer-events: none; }
.search-input {
    width: 100%;
    padding: 12px 16px 12px 44px;
    border: 1px solid #e5e5e5;
    border-radius: 8px;
    font-size: 14px;
    font-family: 'Inter', sans-serif;
    transition: all 0.3s;
    background: #ffffff;
}
.search-input:focus { outline: none; border-color: #000000; box-shadow: 0 0 0 3px rgba(0,0,0,0.05); }
.clear-search {
    position: absolute; right: 12px;
    background: #f0f0f0; border: none;
    width: 24px; height: 24px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    cursor: pointer; color: #666666; transition: all 0.2s;
}
.clear-search:hover { background: #e5e5e5; color: #000000; }
.search-results-text { display: block; margin-top: 8px; font-size: 12px; color: #666666; }

/* Card Header with Count */
.card-header-with-count {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 16px;
}
.item-count {
    background: #fafafa;
    color: #666666;
    padding: 6px 12px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 600;
    border: 1px solid #e5e5e5;
}

/* Empty State */
.empty-state { text-align: center; padding: 60px 20px; color: #666666; }
.empty-state i { font-size: 64px; color: #e5e5e5; margin-bottom: 20px; }
.empty-state h3 { font-size: 18px; font-weight: 600; color: #333333; margin-bottom: 8px; }
.empty-state p { font-size: 14px; margin-bottom: 20px; max-width: 400px; margin-left: auto; margin-right: auto; }

/* Button Styles */
.btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 12px 24px;
    border: none;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s;
    text-decoration: none;
    font-family: 'Inter', sans-serif;
    white-space: nowrap;
}
.btn i { font-size: 16px; }
.btn-primary { background: #000000; color: #ffffff; }
.btn-primary:hover { background: #333333; transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.15); }
.btn-secondary { background: #f5f5f5; color: #333333; border: 1px solid #e5e5e5; }
.btn-secondary:hover { background: #e5e5e5; }

/* Table Styles */
.table-responsive { overflow-x: auto; margin-top: 20px; }
.data-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.data-table thead { background: #fafafa; border-bottom: 2px solid #e5e5e5; }
.data-table th {
    padding: 12px 16px;
    text-align: left;
    font-weight: 600;
    color: #333333;
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.data-table tbody tr { border-bottom: 1px solid #f0f0f0; transition: background 0.2s; }
.data-table tbody tr:hover { background: #fafafa; }
.data-table td { padding: 14px 16px; color: #333333; }

/* Entity Tag */
.entity-tag {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 10px;
    background: #fafafa;
    border: 1px solid #e5e5e5;
    border-radius: 6px;
    font-size: 12px;
    color: #333333;
}
.entity-tag i { font-size: 11px; color: #666666; }

/* Badge */
.badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 600;
}
.badge i { font-size: 10px; }
.badge-success  { background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; }
.badge-inactive { background: #fafafa; color: #666666; border: 1px solid #e5e5e5; }

/* Action Buttons */
.action-buttons { display: flex; gap: 8px; }
.btn-action {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    border-radius: 6px;
    border: 1px solid #e5e5e5;
    background: #ffffff;
    color: #666666;
    cursor: pointer;
    transition: all 0.2s;
    text-decoration: none;
}
.btn-action:hover { transform: translateY(-2px); box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
.btn-edit:hover   { background: #000000; color: #ffffff; border-color: #000000; }
.btn-delete:hover { background: #ef4444; color: #ffffff; border-color: #ef4444; }

/* Modal */
.modal {
    display: none;
    position: fixed;
    top: 0; left: 0;
    width: 100%; height: 100%;
    background: rgba(0,0,0,0.5);
    z-index: 9999;
    align-items: center;
    justify-content: center;
}
.modal.active { display: flex; }
.modal-content {
    background: #ffffff;
    border-radius: 12px;
    width: 90%;
    max-width: 700px;
    max-height: 90vh;
    overflow-y: auto;
    box-shadow: 0 20px 60px rgba(0,0,0,0.3);
}
.modal-header {
    padding: 24px;
    border-bottom: 1px solid #e5e5e5;
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.modal-title { font-size: 18px; font-weight: 700; color: #000000; }
.modal-close {
    background: none; border: none;
    font-size: 24px; color: #666666;
    cursor: pointer; padding: 0;
    width: 32px; height: 32px;
    display: flex; align-items: center; justify-content: center;
    border-radius: 6px; transition: all 0.2s;
}
.modal-close:hover { background: #f0f0f0; color: #000000; }
.modal-body   { padding: 24px; }
.modal-footer {
    padding: 20px 24px;
    border-top: 1px solid #e5e5e5;
    display: flex;
    justify-content: flex-end;
    gap: 12px;
}

/* Form Styles */
.form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px; }
.form-group { margin-bottom: 20px; }
.form-label { display: block; font-size: 13px; font-weight: 600; margin-bottom: 8px; color: #333333; }
.required { color: #ef4444; }
.form-input {
    width: 100%;
    padding: 12px 16px;
    border: 1px solid #e5e5e5;
    border-radius: 8px;
    font-size: 14px;
    font-family: 'Inter', sans-serif;
    transition: all 0.3s;
    background: #ffffff;
    box-sizing: border-box;
}
.form-input:focus { outline: none; border-color: #000000; box-shadow: 0 0 0 3px rgba(0,0,0,0.05); }
.form-input[readonly] { background: #f5f5f5; color: #666666; cursor: not-allowed; }
.form-hint { display: block; font-size: 11px; color: #666666; margin-top: 6px; }

/* Switch Toggle */
.checkbox-wrapper { display: flex; align-items: center; gap: 12px; }
.switch { position: relative; display: inline-block; width: 48px; height: 24px; }
.switch input { opacity: 0; width: 0; height: 0; }
.slider {
    position: absolute; cursor: pointer;
    top: 0; left: 0; right: 0; bottom: 0;
    background-color: #e5e5e5;
    transition: 0.3s;
    border-radius: 24px;
}
.slider:before {
    position: absolute; content: "";
    height: 18px; width: 18px;
    left: 3px; bottom: 3px;
    background-color: white;
    transition: 0.3s;
    border-radius: 50%;
}
input:checked + .slider { background-color: #000000; }
input:checked + .slider:before { transform: translateX(24px); }
.switch-label { font-size: 14px; font-weight: 500; color: #333333; }

/* Responsive */
@media (max-width: 768px) {
    .action-bar { flex-direction: column; }
    .search-box { max-width: 100%; width: 100%; }
    .btn { width: 100%; justify-content: center; }
    .form-row { grid-template-columns: 1fr; }
    .modal-content { width: 95%; margin: 10px; }
    .modal-header, .modal-body, .modal-footer { padding: 16px; }
    .modal-footer { flex-direction: column; }
    .modal-footer .btn { width: 100%; }
    .table-responsive { font-size: 12px; }
    .entity-tag { font-size: 11px; padding: 3px 8px; }
}
</style>

<script>
const holidaysData = <?php
    $allH = mysqli_query($conn, "SELECT * FROM public_holidays ORDER BY id");
    $arr  = [];
    while ($r = mysqli_fetch_assoc($allH)) $arr[] = $r;
    echo json_encode($arr);
?>;

const dayNames = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];

function updateDay(dateVal) {
    const el = document.getElementById('day_display');
    if (!dateVal) { el.value = ''; return; }
    const d = new Date(dateVal + 'T00:00:00');
    el.value = dayNames[d.getDay()];
}

function openModal() {
    document.getElementById('holidayModal').classList.add('active');
    document.getElementById('holidayForm').reset();
    document.getElementById('holiday_id').value = '';
    document.getElementById('day_display').value = '';
    document.getElementById('modalTitle').textContent = 'Add New Holiday';
    document.getElementById('submitBtnText').textContent = 'Save Holiday';
    document.getElementById('active').checked = true;
}

function closeModal() {
    document.getElementById('holidayModal').classList.remove('active');
}

function editHoliday(id) {
    const h = holidaysData.find(x => x.id == id);
    if (!h) { alert('Holiday not found'); return; }
    document.getElementById('holidayModal').classList.add('active');
    document.getElementById('holiday_id').value   = h.id;
    document.getElementById('holiday_date').value = h.holiday_date;
    document.getElementById('day_display').value  = h.day_of_week;
    document.getElementById('holiday_name').value = h.holiday_name;
    document.getElementById('active').checked     = h.active == 1;
    document.getElementById('modalTitle').textContent    = 'Edit Holiday';
    document.getElementById('submitBtnText').textContent = 'Update Holiday';
}

function clearSearch() {
    document.getElementById('searchInput').value = '';
    document.getElementById('searchForm').submit();
}

document.getElementById('holidayModal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});

document.getElementById('holidayForm').addEventListener('submit', function() {
    const btn = document.querySelector('#holidayModal .modal-footer .btn-primary');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving...';
});

let searchTimeout;
document.getElementById('searchInput').addEventListener('input', function() {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => document.getElementById('searchForm').submit(), 500);
});
</script>

<?php include 'footer.php'; ?>