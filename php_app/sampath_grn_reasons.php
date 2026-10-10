<?php
include 'config.php';

// Create sampath_grn_reasons table if not exists
$createTable = "CREATE TABLE IF NOT EXISTS sampath_grn_reasons (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    reason_name VARCHAR(255) NOT NULL,
    active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)";
mysqli_query($conn, $createTable);

// Handle Delete
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    if (mysqli_query($conn, "DELETE FROM sampath_grn_reasons WHERE id = $id")) {
        $success_message = "GRN reason deleted successfully!";
    } else {
        $error_message = "Error deleting GRN reason: " . mysqli_error($conn);
    }
}

// Handle form submission (Create or Update)
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $reason_name = isset($_POST['reason_name']) ? mysqli_real_escape_string($conn, trim($_POST['reason_name'])) : '';
    $active      = isset($_POST['active']) ? 1 : 0;

    if (empty($reason_name)) {
        $error_message = "Reason name is required.";
    } else {
        if (isset($_POST['reason_id']) && !empty($_POST['reason_id'])) {
            $id  = intval($_POST['reason_id']);
            $sql = "UPDATE sampath_grn_reasons SET reason_name = '$reason_name', active = '$active' WHERE id = $id";
            if (mysqli_query($conn, $sql)) {
                $success_message = "GRN reason updated successfully!";
            } else {
                $error_message = "Error: " . mysqli_error($conn);
            }
        } else {
            $sql = "INSERT INTO sampath_grn_reasons (reason_name, active) VALUES ('$reason_name', '$active')";
            if (mysqli_query($conn, $sql)) {
                $success_message = "GRN reason added successfully!";
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
    $search_query = " WHERE reason_name LIKE '%$search%'";
}

$reasons_sql    = "SELECT * FROM sampath_grn_reasons $search_query ORDER BY id ASC";
$reasons_result = mysqli_query($conn, $reasons_sql);
$total_reasons  = mysqli_num_rows($reasons_result);

include 'header.php';
?>

<div class="page-header">
    <h2 class="page-title">Sampath GRN Item Reasons</h2>
    <p class="page-subtitle">Manage GRN item reasons — add, edit, and delete reasons</p>
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
                    placeholder="Search GRN reasons..."
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
            Found <?php echo $total_reasons; ?> result<?php echo $total_reasons != 1 ? 's' : ''; ?> for "<?php echo htmlspecialchars($search); ?>"
        </small>
        <?php endif; ?>
    </div>

    <button onclick="openModal()" class="btn btn-primary">
        <i class="fa-solid fa-plus"></i>
        Add New GRN Reason
    </button>
</div>

<div class="content-card">
    <div class="card-header-with-count">
        <h3 class="card-title">All GRN Item Reasons</h3>
        <span class="item-count"><?php echo $total_reasons; ?> reason<?php echo $total_reasons != 1 ? 's' : ''; ?></span>
    </div>

    <?php if (mysqli_num_rows($reasons_result) > 0): ?>
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Reason Name</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($reason = mysqli_fetch_assoc($reasons_result)): ?>
                <tr>
                    <td><?php echo $reason['id']; ?></td>
                    <td><strong><?php echo htmlspecialchars($reason['reason_name']); ?></strong></td>
                    <td>
                        <?php if ($reason['active']): ?>
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
                               onclick="editReason(<?php echo $reason['id']; ?>)"
                               class="btn-action btn-edit"
                               title="Edit">
                                <i class="fa-solid fa-pen"></i>
                            </a>
                            <a href="?delete=<?php echo $reason['id']; ?><?php echo !empty($search) ? '&search='.urlencode($search) : ''; ?>"
                               class="btn-action btn-delete"
                               title="Delete"
                               onclick="return confirm('Are you sure you want to delete this GRN reason?')">
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
        <i class="fa-solid fa-clipboard-list"></i>
        <h3>No GRN Reasons Found</h3>
        <p>
            <?php if (!empty($search)): ?>
                No GRN reasons match your search. Try a different term.
            <?php else: ?>
                Add your first GRN reason using the "Add New GRN Reason" button above.
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
<div id="reasonModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title" id="modalTitle">Add New GRN Reason</h3>
            <button class="modal-close" onclick="closeModal()">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form method="POST" action="" id="reasonForm">
            <input type="hidden" name="reason_id" id="reason_id">

            <div class="modal-body">
                <div class="form-group">
                    <label for="reason_name" class="form-label">Reason Name <span class="required">*</span></label>
                    <input type="text" name="reason_name" id="reason_name" class="form-input" placeholder="e.g. Damaged Goods" required>
                    <small class="form-hint">Enter the GRN item reason name</small>
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
                    <span id="submitBtnText">Save GRN Reason</span>
                </button>
            </div>
        </form>
    </div>
</div>

<style>
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

.empty-state { text-align: center; padding: 60px 20px; color: #666666; }
.empty-state i { font-size: 64px; color: #e5e5e5; margin-bottom: 20px; display: block; }
.empty-state h3 { font-size: 18px; font-weight: 600; color: #333333; margin-bottom: 8px; }
.empty-state p { font-size: 14px; margin-bottom: 20px; max-width: 400px; margin-left: auto; margin-right: auto; }

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
    max-width: 500px;
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
.form-hint { display: block; font-size: 11px; color: #666666; margin-top: 6px; }

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

@media (max-width: 768px) {
    .action-bar { flex-direction: column; }
    .search-box { max-width: 100%; width: 100%; }
    .btn { width: 100%; justify-content: center; }
    .modal-content { width: 95%; margin: 10px; }
    .modal-header, .modal-body, .modal-footer { padding: 16px; }
    .modal-footer { flex-direction: column; }
    .modal-footer .btn { width: 100%; }
}
</style>

<script>
const reasonsData = <?php
    $allR = mysqli_query($conn, "SELECT * FROM sampath_grn_reasons ORDER BY id");
    $arr  = [];
    while ($r = mysqli_fetch_assoc($allR)) $arr[] = $r;
    echo json_encode($arr);
?>;

function openModal() {
    document.getElementById('reasonModal').classList.add('active');
    document.getElementById('reasonForm').reset();
    document.getElementById('reason_id').value = '';
    document.getElementById('modalTitle').textContent = 'Add New GRN Reason';
    document.getElementById('submitBtnText').textContent = 'Save GRN Reason';
    document.getElementById('active').checked = true;
}

function closeModal() {
    document.getElementById('reasonModal').classList.remove('active');
}

function editReason(id) {
    const r = reasonsData.find(x => x.id == id);
    if (!r) { alert('GRN reason not found'); return; }
    document.getElementById('reasonModal').classList.add('active');
    document.getElementById('reason_id').value    = r.id;
    document.getElementById('reason_name').value  = r.reason_name;
    document.getElementById('active').checked     = r.active == 1;
    document.getElementById('modalTitle').textContent    = 'Edit GRN Reason';
    document.getElementById('submitBtnText').textContent = 'Update GRN Reason';
}

function clearSearch() {
    document.getElementById('searchInput').value = '';
    document.getElementById('searchForm').submit();
}

document.getElementById('reasonModal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});

document.getElementById('reasonForm').addEventListener('submit', function() {
    const btn = document.querySelector('#reasonModal .modal-footer .btn-primary');
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