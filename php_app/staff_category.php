<?php
include 'config.php';

// ── Create / ensure staff_categories table ────────────────────────────────────
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS staff_categories (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    category_code VARCHAR(50) NOT NULL UNIQUE,
    category_name VARCHAR(255) NOT NULL,
    active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_category_code (category_code)
)");

// ── Handle Delete ─────────────────────────────────────────────────────────────
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    if (mysqli_query($conn, "DELETE FROM staff_categories WHERE id = $id")) {
        $success_message = "Staff Category deleted successfully!";
    } else {
        $error_message = "Error deleting staff category: " . mysqli_error($conn);
    }
}

// ── Handle Create / Update ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $category_code = mysqli_real_escape_string($conn, trim($_POST['category_code']));
    $category_name = mysqli_real_escape_string($conn, trim($_POST['category_name']));
    $active        = isset($_POST['active']) ? 1 : 0;

    if (isset($_POST['category_id']) && !empty($_POST['category_id'])) {
        // ── UPDATE ────────────────────────────────────────────────────────────
        $id = intval($_POST['category_id']);

        $check_result = mysqli_query($conn, "SELECT id FROM staff_categories WHERE category_code = '$category_code' AND id != $id");
        if (mysqli_num_rows($check_result) > 0) {
            $error_message = "Category code already exists. Please use a different code.";
        } else {
            $sql = "UPDATE staff_categories SET
                        category_code = '$category_code',
                        category_name = '$category_name',
                        active        = $active
                    WHERE id = $id";
            if (mysqli_query($conn, $sql)) {
                $success_message = "Staff Category updated successfully!";
            } else {
                $error_message = "Error: " . mysqli_error($conn);
            }
        }

    } else {
        // ── INSERT ────────────────────────────────────────────────────────────
        $check_result = mysqli_query($conn, "SELECT id FROM staff_categories WHERE category_code = '$category_code'");
        if (mysqli_num_rows($check_result) > 0) {
            $error_message = "Category code already exists. Please use a different code.";
        } else {
            $sql = "INSERT INTO staff_categories (category_code, category_name, active)
                    VALUES ('$category_code', '$category_name', $active)";
            if (mysqli_query($conn, $sql)) {
                $success_message = "Staff Category created successfully!";
            } else {
                $error_message = "Error: " . mysqli_error($conn);
            }
        }
    }
}

// ── Fetch categories ──────────────────────────────────────────────────────────
$categories_result = mysqli_query($conn,
    "SELECT * FROM staff_categories ORDER BY created_at DESC");

include 'header.php';
?>

<div class="page-header">
    <h2 class="page-title">Staff Category Management</h2>
    <p class="page-subtitle">Manage staff categories</p>
</div>

<?php if (isset($success_message)): ?>
<div class="alert alert-success">
    <i class="fa-solid fa-circle-check"></i> <?php echo htmlspecialchars($success_message); ?>
</div>
<?php endif; ?>
<?php if (isset($error_message)): ?>
<div class="alert alert-error">
    <i class="fa-solid fa-circle-exclamation"></i> <?php echo htmlspecialchars($error_message); ?>
</div>
<?php endif; ?>

<div style="margin-bottom:20px;">
    <button onclick="openModal()" class="btn btn-primary">
        <i class="fa-solid fa-plus"></i> Add New Category
    </button>
</div>

<!-- ── Categories Table ───────────────────────────────────────────────────── -->
<div class="content-card">
    <h3 class="card-title">All Staff Categories</h3>
    <?php if ($categories_result && mysqli_num_rows($categories_result) > 0): ?>
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Code</th>
                    <th>Category Name</th>
                    <th>Status</th>
                    <th>Created</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($category = mysqli_fetch_assoc($categories_result)): ?>
                <tr>
                    <td><?php echo $category['id']; ?></td>
                    <td><strong><?php echo htmlspecialchars($category['category_code']); ?></strong></td>
                    <td><?php echo htmlspecialchars($category['category_name']); ?></td>
                    <td>
                        <?php if ($category['active']): ?>
                            <span class="badge badge-success"><i class="fa-solid fa-circle-check"></i> Active</span>
                        <?php else: ?>
                            <span class="badge badge-inactive"><i class="fa-solid fa-circle-xmark"></i> Inactive</span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo date('M d, Y', strtotime($category['created_at'])); ?></td>
                    <td>
                        <div class="action-buttons">
                            <a href="#"
                               onclick="editCategory(<?php echo htmlspecialchars(json_encode($category)); ?>)"
                               class="btn-action btn-edit" title="Edit">
                                <i class="fa-solid fa-pen"></i>
                            </a>
                            <a href="?delete=<?php echo $category['id']; ?>"
                               class="btn-action btn-delete" title="Delete"
                               onclick="return confirm('Are you sure you want to delete this staff category?')">
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
    <p style="color:#666;font-size:13px;text-align:center;padding:30px;">
        No staff categories found. Create your first category using the button above.
    </p>
    <?php endif; ?>
</div>

<!-- ── Modal ─────────────────────────────────────────────────────────────── -->
<div id="categoryModal" class="modal">
    <div class="modal-content">

        <div class="modal-header">
            <div class="modal-header-inner">
                <div class="modal-icon"><i class="fa-solid fa-layer-group"></i></div>
                <div>
                    <h3 class="modal-title" id="modalTitle">Add New Staff Category</h3>
                    <p class="modal-subtitle" id="modalSubtitle">Fill in the details to create a new staff category</p>
                </div>
            </div>
            <button class="modal-close" onclick="closeModal()">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form method="POST" action="" id="categoryForm">
            <input type="hidden" name="category_id" id="category_id">

            <div class="modal-body">

                <!-- ── Basic Info ─────────────────────────────────────── -->
                <div class="modal-section">
                    <div class="section-label">
                        <i class="fa-solid fa-circle-info"></i> Basic Information
                    </div>
                    <div class="form-row-2">
                        <div class="form-group">
                            <label for="category_code" class="form-label">
                                Category Code <span class="required">*</span>
                            </label>
                            <input type="text" id="category_code" name="category_code"
                                   class="form-input"
                                   placeholder="e.g. PERM, TEMP, CONTRACT"
                                   pattern="[A-Za-z0-9]+"
                                   title="Only letters and numbers allowed"
                                   required>
                            <small class="form-hint">Unique code — letters and numbers only</small>
                        </div>
                        <div class="form-group">
                            <label for="category_name" class="form-label">
                                Category Name <span class="required">*</span>
                            </label>
                            <input type="text" id="category_name" name="category_name"
                                   class="form-input"
                                   placeholder="e.g. Permanent, Temporary, Contract"
                                   required>
                        </div>
                    </div>
                </div>

                <!-- ── Status ─────────────────────────────────────────── -->
                <div class="modal-section">
                    <div class="section-label">
                        <i class="fa-solid fa-toggle-on"></i> Status
                    </div>
                    <label class="toggle-label">
                        <input type="checkbox" name="active" id="active" class="toggle-input" checked>
                        <span class="toggle-track"><span class="toggle-thumb"></span></span>
                        <span class="toggle-text" id="toggleText">
                            Active — this category is available for assignment
                        </span>
                    </label>
                </div>

            </div><!-- /modal-body -->

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal()">
                    <i class="fa-solid fa-xmark"></i> Cancel
                </button>
                <button type="submit" class="btn btn-primary">
                    <i class="fa-solid fa-check"></i>
                    <span id="submitBtnText">Save Category</span>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ── Styles ─────────────────────────────────────────────────────────────── -->
<style>
.alert {
    padding:16px 20px; border-radius:8px; margin-bottom:24px;
    display:flex; align-items:center; gap:12px; font-size:13px; font-weight:500;
}
.alert i { font-size:18px; }
.alert-success { background:#f0fdf4; color:#166534; border:1px solid #bbf7d0; }
.alert-error   { background:#fef2f2; color:#991b1b; border:1px solid #fecaca; }

.btn {
    display:inline-flex; align-items:center; gap:8px;
    padding:12px 24px; border:none; border-radius:8px;
    font-size:14px; font-weight:600; cursor:pointer;
    transition:all .3s; text-decoration:none; font-family:'Inter',sans-serif;
}
.btn i { font-size:16px; }
.btn-primary        { background:#000; color:#fff; }
.btn-primary:hover  { background:#333; transform:translateY(-2px); box-shadow:0 4px 12px rgba(0,0,0,.15); }
.btn-secondary      { background:#f5f5f5; color:#333; border:1px solid #e5e5e5; }
.btn-secondary:hover{ background:#e5e5e5; }

.content-card { background:#fff; border:1px solid #e5e5e5; border-radius:12px; padding:24px; margin-bottom:24px; }
.card-title   { font-size:18px; font-weight:600; margin-bottom:20px; color:#1f2937; }

.table-responsive { overflow-x:auto; }
.data-table { width:100%; border-collapse:collapse; font-size:13px; }
.data-table thead { background:#fafafa; border-bottom:2px solid #e5e5e5; }
.data-table th {
    padding:12px 14px; text-align:left; font-weight:600;
    color:#333; font-size:11px; text-transform:uppercase; letter-spacing:.5px; white-space:nowrap;
}
.data-table tbody tr { border-bottom:1px solid #f0f0f0; transition:background .15s; }
.data-table tbody tr:hover { background:#fafafa; }
.data-table td { padding:12px 14px; color:#333; vertical-align:middle; }

.badge { display:inline-flex; align-items:center; gap:5px; padding:4px 10px; border-radius:12px; font-size:11px; font-weight:600; }
.badge i { font-size:10px; }
.badge-success  { background:#f0fdf4; color:#166534; border:1px solid #bbf7d0; }
.badge-inactive { background:#fafafa; color:#666;    border:1px solid #e5e5e5; }

.action-buttons { display:flex; gap:8px; }
.btn-action {
    display:inline-flex; align-items:center; justify-content:center;
    width:32px; height:32px; border-radius:6px;
    border:1px solid #e5e5e5; background:#fff; color:#666;
    cursor:pointer; transition:all .2s; text-decoration:none;
}
.btn-action:hover { transform:translateY(-2px); box-shadow:0 2px 8px rgba(0,0,0,.1); }
.btn-edit:hover   { background:#000; color:#fff; border-color:#000; }
.btn-delete:hover { background:#ef4444; color:#fff; border-color:#ef4444; }

.modal {
    display:none; position:fixed; z-index:9999;
    inset:0; background:rgba(0,0,0,.6); backdrop-filter:blur(4px);
}
.modal.active { display:flex; align-items:center; justify-content:center; padding:20px; }
@keyframes slideUp {
    from { opacity:0; transform:translateY(50px) scale(.97); }
    to   { opacity:1; transform:translateY(0) scale(1); }
}
.modal-content {
    background:#fff; border-radius:16px;
    width:100%; max-width:560px; max-height:92vh; overflow-y:auto;
    box-shadow:0 32px 100px rgba(0,0,0,.3);
    animation:slideUp .3s cubic-bezier(.22,.68,0,1.2);
}
.modal-header {
    display:flex; justify-content:space-between; align-items:flex-start;
    padding:28px 32px 24px; border-bottom:1px solid #f0f0f0;
    background:linear-gradient(135deg,#f9fafb,#fff);
    border-radius:16px 16px 0 0;
    position:sticky; top:0; z-index:10;
}
.modal-header-inner { display:flex; align-items:center; gap:16px; }
.modal-icon {
    width:50px; height:50px; border-radius:12px; background:#111; color:#fff;
    display:flex; align-items:center; justify-content:center; font-size:22px; flex-shrink:0;
}
.modal-title    { font-size:20px; font-weight:700; margin:0 0 4px; color:#111; }
.modal-subtitle { font-size:13px; color:#888; margin:0; }
.modal-close {
    background:none; border:none; font-size:20px; cursor:pointer;
    color:#888; width:38px; height:38px;
    display:flex; align-items:center; justify-content:center;
    border-radius:8px; transition:all .2s; flex-shrink:0;
}
.modal-close:hover { background:#f5f5f5; color:#000; }

.modal-body { padding:28px 32px; display:flex; flex-direction:column; gap:20px; }
.modal-section { background:#fafafa; border:1px solid #efefef; border-radius:12px; padding:22px 24px; }
.section-label {
    font-size:11px; font-weight:700; text-transform:uppercase;
    letter-spacing:.8px; color:#555; margin-bottom:16px;
    display:flex; align-items:center; gap:8px;
}
.section-label i { color:#888; }

.form-row-2 { display:grid; grid-template-columns:1fr 1fr; gap:20px; }
.form-group { display:flex; flex-direction:column; }
.form-label { font-size:13px; font-weight:600; margin-bottom:8px; color:#333; }
.required   { color:#ef4444; }
.form-input {
    width:100%; padding:11px 14px; border:1.5px solid #e0e0e0; border-radius:8px;
    font-size:14px; font-family:'Inter',sans-serif; transition:all .25s;
    background:#fff; box-sizing:border-box;
}
.form-input:focus { outline:none; border-color:#111; box-shadow:0 0 0 3px rgba(0,0,0,.06); }
.form-input::placeholder { color:#bbb; }
.form-hint { font-size:11px; color:#888; margin-top:6px; }

.toggle-label { display:flex; align-items:center; gap:14px; cursor:pointer; }
.toggle-input { display:none; }
.toggle-track {
    width:46px; height:26px; border-radius:13px; background:#d1d5db;
    position:relative; transition:background .25s; flex-shrink:0;
}
.toggle-input:checked + .toggle-track { background:#111; }
.toggle-thumb {
    width:20px; height:20px; border-radius:50%; background:#fff;
    position:absolute; top:3px; left:3px;
    transition:transform .25s; box-shadow:0 1px 4px rgba(0,0,0,.25);
}
.toggle-input:checked + .toggle-track .toggle-thumb { transform:translateX(20px); }
.toggle-text { font-size:13px; color:#444; font-weight:500; }

.modal-footer {
    display:flex; justify-content:flex-end; gap:12px;
    padding:20px 32px 28px; border-top:1px solid #f0f0f0;
    position:sticky; bottom:0; background:#fff; z-index:10;
}

@media (max-width:600px) {
    .modal-header, .modal-body, .modal-footer { padding:18px; }
    .form-row-2 { grid-template-columns:1fr; }
    .modal-footer { flex-direction:column; }
    .modal-footer .btn { width:100%; justify-content:center; }
}
</style>

<!-- ── Scripts ────────────────────────────────────────────────────────────── -->
<script>
function openModal() {
    resetModal();
    document.getElementById('modalTitle').textContent    = 'Add New Staff Category';
    document.getElementById('modalSubtitle').textContent = 'Fill in the details to create a new staff category';
    document.getElementById('submitBtnText').textContent = 'Save Category';
    document.getElementById('categoryModal').classList.add('active');
}

function closeModal() {
    document.getElementById('categoryModal').classList.remove('active');
}

function resetModal() {
    document.getElementById('categoryForm').reset();
    document.getElementById('category_id').value = '';
    document.getElementById('active').checked    = true;
    updateToggleText();
}

function editCategory(cat) {
    resetModal();
    document.getElementById('category_id').value    = cat.id;
    document.getElementById('category_code').value  = cat.category_code;
    document.getElementById('category_name').value  = cat.category_name;
    document.getElementById('active').checked       = cat.active == 1;

    document.getElementById('modalTitle').textContent    = 'Edit Staff Category';
    document.getElementById('modalSubtitle').textContent = 'Editing: ' + cat.category_name;
    document.getElementById('submitBtnText').textContent = 'Update Category';

    updateToggleText();
    document.getElementById('categoryModal').classList.add('active');
}

function updateToggleText() {
    const tog = document.getElementById('active');
    const txt = document.getElementById('toggleText');
    if (!tog || !txt) return;
    txt.textContent = tog.checked
        ? 'Active — this category is available for assignment'
        : 'Inactive — this category is hidden from assignment';
}

document.addEventListener('DOMContentLoaded', function () {
    const tog = document.getElementById('active');
    if (tog) tog.addEventListener('change', updateToggleText);
});

window.addEventListener('click', function (e) {
    if (e.target === document.getElementById('categoryModal')) closeModal();
});
</script>

<?php include 'footer.php'; ?>