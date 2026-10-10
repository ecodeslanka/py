<?php
include 'config.php';

// Create notifications table if not exists
$createTable = "CREATE TABLE IF NOT EXISTS notifications (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    type ENUM('info','success','warning','danger') DEFAULT 'info',
    valid_from DATETIME NOT NULL,
    valid_until DATETIME NOT NULL,
    active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)";
mysqli_query($conn, $createTable);





// Handle Delete
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    if (mysqli_query($conn, "DELETE FROM notifications WHERE id = $id")) {
        $success_message = "Notification deleted successfully!";
    } else {
        $error_message = "Error deleting notification: " . mysqli_error($conn);
    }
}

// Handle Toggle Active
if (isset($_GET['toggle'])) {
    $id  = intval($_GET['toggle']);
    $row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT active FROM notifications WHERE id = $id"));
    if ($row) {
        $newActive = $row['active'] ? 0 : 1;
        mysqli_query($conn, "UPDATE notifications SET active = $newActive WHERE id = $id");
        $success_message = "Notification " . ($newActive ? "activated" : "deactivated") . " successfully!";
    }
}

// Handle form submission (Create or Update)
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $title       = isset($_POST['title'])       ? mysqli_real_escape_string($conn, trim($_POST['title']))       : '';
    $message     = isset($_POST['message'])     ? mysqli_real_escape_string($conn, trim($_POST['message']))     : '';
    $type        = isset($_POST['type'])        ? mysqli_real_escape_string($conn, trim($_POST['type']))        : 'info';
    $valid_from  = isset($_POST['valid_from'])  ? mysqli_real_escape_string($conn, trim($_POST['valid_from']))  : '';
    $valid_until = isset($_POST['valid_until']) ? mysqli_real_escape_string($conn, trim($_POST['valid_until'])) : '';
    $active      = isset($_POST['active'])      ? 1 : 0;

    if (empty($title) || empty($message) || empty($valid_from) || empty($valid_until)) {
        $error_message = "Title, message, valid from, and valid until are required fields.";
    } elseif (strtotime($valid_until) <= strtotime($valid_from)) {
        $error_message = "Valid Until must be later than Valid From.";
    } else {
        if (isset($_POST['notification_id']) && !empty($_POST['notification_id'])) {
            $id  = intval($_POST['notification_id']);
            $sql = "UPDATE notifications SET
                        title       = '$title',
                        message     = '$message',
                        type        = '$type',
                        valid_from  = '$valid_from',
                        valid_until = '$valid_until',
                        active      = '$active'
                    WHERE id = $id";
            if (mysqli_query($conn, $sql)) {
                $success_message = "Notification updated successfully!";
            } else {
                $error_message = "Error: " . mysqli_error($conn);
            }
        } else {
            $sql = "INSERT INTO notifications (title, message, type, valid_from, valid_until, active)
                    VALUES ('$title','$message','$type','$valid_from','$valid_until','$active')";
            if (mysqli_query($conn, $sql)) {
                $success_message = "Notification added successfully!";
            } else {
                $error_message = "Error: " . mysqli_error($conn);
            }
        }
    }
}

// Handle search & filter
$search       = '';
$filter_type   = '';
$filter_status = '';
$where_parts  = [];

if (isset($_GET['search']) && !empty(trim($_GET['search']))) {
    $search       = mysqli_real_escape_string($conn, trim($_GET['search']));
    $where_parts[] = "(title LIKE '%$search%' OR message LIKE '%$search%')";
}
if (isset($_GET['filter_type']) && !empty($_GET['filter_type'])) {
    $filter_type   = mysqli_real_escape_string($conn, $_GET['filter_type']);
    $where_parts[] = "type = '$filter_type'";
}
if (isset($_GET['filter_status']) && $_GET['filter_status'] !== '') {
    $filter_status = $_GET['filter_status'];
    if ($filter_status === 'active')   $where_parts[] = "active = 1 AND valid_until >= NOW()";
    if ($filter_status === 'inactive') $where_parts[] = "active = 0";
    if ($filter_status === 'expired')  $where_parts[] = "valid_until < NOW()";
}

$where_sql = count($where_parts) ? " WHERE " . implode(" AND ", $where_parts) : "";

$notifications_sql    = "SELECT *, 
    CASE WHEN active = 1 AND valid_from <= NOW() AND valid_until >= NOW() THEN 'live'
         WHEN active = 1 AND valid_from > NOW() THEN 'scheduled'
         WHEN valid_until < NOW() THEN 'expired'
         ELSE 'inactive'
    END AS display_status
    FROM notifications $where_sql ORDER BY created_at DESC";
$notifications_result = mysqli_query($conn, $notifications_sql);
$total_notifications  = mysqli_num_rows($notifications_result);

// Summary counts
$counts = mysqli_fetch_assoc(mysqli_query($conn, "SELECT
    COUNT(*) AS total,
    SUM(CASE WHEN active=1 AND valid_from <= NOW() AND valid_until >= NOW() THEN 1 ELSE 0 END) AS live,
    SUM(CASE WHEN active=1 AND valid_from > NOW() THEN 1 ELSE 0 END) AS scheduled,
    SUM(CASE WHEN valid_until < NOW() THEN 1 ELSE 0 END) AS expired
    FROM notifications"));

include 'header.php';
?>

<div class="page-header">
    <h2 class="page-title">Notification Center</h2>
    <p class="page-subtitle">Create and manage system notifications with validity windows</p>
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

<!-- Summary Cards -->
<div class="summary-grid">
    <div class="summary-card">
        <div class="summary-icon icon-total"><i class="fa-solid fa-bell"></i></div>
        <div class="summary-info">
            <span class="summary-value"><?php echo $counts['total']; ?></span>
            <span class="summary-label">Total</span>
        </div>
    </div>
    <div class="summary-card">
        <div class="summary-icon icon-live"><i class="fa-solid fa-signal"></i></div>
        <div class="summary-info">
            <span class="summary-value"><?php echo $counts['live']; ?></span>
            <span class="summary-label">Live Now</span>
        </div>
    </div>
    <div class="summary-card">
        <div class="summary-icon icon-scheduled"><i class="fa-solid fa-clock"></i></div>
        <div class="summary-info">
            <span class="summary-value"><?php echo $counts['scheduled']; ?></span>
            <span class="summary-label">Scheduled</span>
        </div>
    </div>
    <div class="summary-card">
        <div class="summary-icon icon-expired"><i class="fa-solid fa-calendar-xmark"></i></div>
        <div class="summary-info">
            <span class="summary-value"><?php echo $counts['expired']; ?></span>
            <span class="summary-label">Expired</span>
        </div>
    </div>
</div>

<!-- Action Bar -->
<div class="action-bar">
    <div class="search-filter-row">
        <form method="GET" action="" id="searchForm">
            <div class="search-input-wrapper">
                <i class="fa-solid fa-search search-icon"></i>
                <input type="text" name="search" id="searchInput" class="search-input"
                    placeholder="Search notifications..."
                    value="<?php echo htmlspecialchars($search); ?>">
                <?php if (!empty($search)): ?>
                <button type="button" class="clear-search" onclick="clearSearch()">
                    <i class="fa-solid fa-xmark"></i>
                </button>
                <?php endif; ?>
            </div>

            <select name="filter_type" class="filter-select" onchange="document.getElementById('searchForm').submit()">
                <option value="">All Types</option>
                <option value="info"    <?php echo $filter_type=='info'    ? 'selected':''; ?>>Info</option>
                <option value="success" <?php echo $filter_type=='success' ? 'selected':''; ?>>Success</option>
                <option value="warning" <?php echo $filter_type=='warning' ? 'selected':''; ?>>Warning</option>
                <option value="danger"  <?php echo $filter_type=='danger'  ? 'selected':''; ?>>Danger</option>
            </select>

            <select name="filter_status" class="filter-select" onchange="document.getElementById('searchForm').submit()">
                <option value="">All Status</option>
                <option value="active"   <?php echo $filter_status=='active'   ? 'selected':''; ?>>Live</option>
                <option value="scheduled"<?php echo $filter_status=='scheduled'? 'selected':''; ?>>Scheduled</option>
                <option value="inactive" <?php echo $filter_status=='inactive' ? 'selected':''; ?>>Inactive</option>
                <option value="expired"  <?php echo $filter_status=='expired'  ? 'selected':''; ?>>Expired</option>
            </select>
        </form>
    </div>

    <button onclick="openModal()" class="btn btn-primary">
        <i class="fa-solid fa-plus"></i>
        Add Notification
    </button>
</div>

<!-- Table -->
<div class="content-card">
    <div class="card-header-with-count">
        <h3 class="card-title">All Notifications</h3>
        <span class="item-count"><?php echo $total_notifications; ?> notification<?php echo $total_notifications != 1 ? 's' : ''; ?></span>
    </div>

    <?php if ($total_notifications > 0): ?>
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Type</th>
                    <th>Title &amp; Message</th>
                    <th>Valid From</th>
                    <th>Valid Until</th>
                    <th>Time Left</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($n = mysqli_fetch_assoc($notifications_result)):
                    $now         = time();
                    $validFrom   = strtotime($n['valid_from']);
                    $validUntil  = strtotime($n['valid_until']);
                    $isExpired   = $validUntil < $now;
                    $isScheduled = $validFrom > $now && $n['active'];
                    $isLive      = $n['active'] && $validFrom <= $now && $validUntil >= $now;

                    // Countdown
                    $timeLeftStr = '';
                    if ($isLive) {
                        $diff = $validUntil - $now;
                        $days = floor($diff / 86400);
                        $hrs  = floor(($diff % 86400) / 3600);
                        $mins = floor(($diff % 3600) / 60);
                        if ($days > 0)      $timeLeftStr = "{$days}d {$hrs}h left";
                        elseif ($hrs > 0)   $timeLeftStr = "{$hrs}h {$mins}m left";
                        else                $timeLeftStr = "{$mins}m left";
                    } elseif ($isScheduled) {
                        $diff = $validFrom - $now;
                        $days = floor($diff / 86400);
                        $hrs  = floor(($diff % 86400) / 3600);
                        $timeLeftStr = "Starts in {$days}d {$hrs}h";
                    } elseif ($isExpired) {
                        $timeLeftStr = "Expired";
                    } else {
                        $timeLeftStr = "—";
                    }
                ?>
                <tr>
                    <td><?php echo $n['id']; ?></td>
                    <td>
                        <span class="type-badge type-<?php echo $n['type']; ?>">
                            <i class="fa-solid <?php
                                echo $n['type'] == 'info'    ? 'fa-circle-info'      :
                                    ($n['type'] == 'success' ? 'fa-circle-check'     :
                                    ($n['type'] == 'warning' ? 'fa-triangle-exclamation' :
                                    'fa-circle-exclamation'));
                            ?>"></i>
                            <?php echo ucfirst($n['type']); ?>
                        </span>
                    </td>
                    <td class="title-cell">
                        <strong><?php echo htmlspecialchars($n['title']); ?></strong>
                        <p class="message-preview"><?php echo htmlspecialchars(mb_strimwidth($n['message'], 0, 90, '...')); ?></p>
                    </td>
                    <td><span class="date-text"><?php echo date('d-M-y H:i', strtotime($n['valid_from'])); ?></span></td>
                    <td><span class="date-text <?php echo $isExpired ? 'expired-date' : ''; ?>"><?php echo date('d-M-y H:i', strtotime($n['valid_until'])); ?></span></td>
                    <td>
                        <span class="countdown-badge <?php
                            echo $isLive      ? 'countdown-live'      :
                                ($isScheduled ? 'countdown-scheduled' :
                                ($isExpired   ? 'countdown-expired'   : 'countdown-off'));
                        ?>">
                            <?php if ($isLive): ?><span class="pulse-dot"></span><?php endif; ?>
                            <?php echo $timeLeftStr; ?>
                        </span>
                    </td>
                    <td>
                        <?php
                            $ds = $n['display_status'];
                            $badgeClass = $ds === 'live'      ? 'badge-live'      :
                                         ($ds === 'scheduled' ? 'badge-scheduled' :
                                         ($ds === 'expired'   ? 'badge-expired'   : 'badge-inactive'));
                            $badgeIcon  = $ds === 'live'      ? 'fa-signal'            :
                                         ($ds === 'scheduled' ? 'fa-clock'             :
                                         ($ds === 'expired'   ? 'fa-calendar-xmark'    : 'fa-circle-xmark'));
                        ?>
                        <span class="badge <?php echo $badgeClass; ?>">
                            <i class="fa-solid <?php echo $badgeIcon; ?>"></i>
                            <?php echo ucfirst($ds); ?>
                        </span>
                    </td>
                    <td>
                        <div class="action-buttons">
                            <a href="#" onclick="viewNotification(<?php echo $n['id']; ?>)"
                               class="btn-action btn-view" title="View">
                                <i class="fa-solid fa-eye"></i>
                            </a>
                            <a href="#" onclick="editNotification(<?php echo $n['id']; ?>)"
                               class="btn-action btn-edit" title="Edit">
                                <i class="fa-solid fa-pen"></i>
                            </a>
                            <a href="?toggle=<?php echo $n['id']; ?>"
                               class="btn-action btn-toggle" title="<?php echo $n['active'] ? 'Deactivate' : 'Activate'; ?>">
                                <i class="fa-solid <?php echo $n['active'] ? 'fa-toggle-on' : 'fa-toggle-off'; ?>"></i>
                            </a>
                            <a href="?delete=<?php echo $n['id']; ?>"
                               class="btn-action btn-delete" title="Delete"
                               onclick="return confirm('Delete this notification?')">
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
        <i class="fa-solid fa-bell-slash"></i>
        <h3>No Notifications Found</h3>
        <p><?php echo !empty($search) || !empty($filter_type) || !empty($filter_status)
            ? 'No notifications match your filter criteria.'
            : 'Create your first notification using the "Add Notification" button above.'; ?>
        </p>
    </div>
    <?php endif; ?>
</div>

<!-- Add / Edit Modal -->
<div id="notificationModal" class="modal">
    <div class="modal-content modal-wide">
        <div class="modal-header">
            <h3 class="modal-title" id="modalTitle">Add Notification</h3>
            <button class="modal-close" onclick="closeModal()">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form method="POST" action="" id="notificationForm">
            <input type="hidden" name="notification_id" id="notification_id">

            <div class="modal-body">

                <!-- Type selector pills -->
                <div class="form-group">
                    <label class="form-label">Notification Type <span class="required">*</span></label>
                    <div class="type-pills" id="typePills">
                        <label class="type-pill pill-info">
                            <input type="radio" name="type" value="info" checked>
                            <i class="fa-solid fa-circle-info"></i> Info
                        </label>
                        <label class="type-pill pill-success">
                            <input type="radio" name="type" value="success">
                            <i class="fa-solid fa-circle-check"></i> Success
                        </label>
                        <label class="type-pill pill-warning">
                            <input type="radio" name="type" value="warning">
                            <i class="fa-solid fa-triangle-exclamation"></i> Warning
                        </label>
                        <label class="type-pill pill-danger">
                            <input type="radio" name="type" value="danger">
                            <i class="fa-solid fa-circle-exclamation"></i> Danger
                        </label>
                    </div>
                </div>

                <div class="form-group">
                    <label for="n_title" class="form-label">Title <span class="required">*</span></label>
                    <input type="text" name="title" id="n_title" class="form-input"
                           placeholder="e.g. System Maintenance Notice" required>
                </div>

                <div class="form-group">
                    <label for="n_message" class="form-label">Message <span class="required">*</span></label>
                    <textarea name="message" id="n_message" class="form-input form-textarea"
                              placeholder="Enter the full notification message..." required></textarea>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="n_valid_from" class="form-label">Valid From <span class="required">*</span></label>
                        <input type="datetime-local" name="valid_from" id="n_valid_from" class="form-input" required>
                        <small class="form-hint">Notification starts showing from this date & time</small>
                    </div>
                    <div class="form-group">
                        <label for="n_valid_until" class="form-label">Valid Until <span class="required">*</span></label>
                        <input type="datetime-local" name="valid_until" id="n_valid_until" class="form-input" required>
                        <small class="form-hint">Notification stops showing after this date & time</small>
                    </div>
                </div>

                <!-- Live duration preview -->
                <div id="durationPreview" class="duration-preview" style="display:none;">
                    <i class="fa-solid fa-clock"></i>
                    <span id="durationText">Duration will appear here</span>
                </div>

                <div class="form-group">
                    <label class="form-label">Status</label>
                    <div class="checkbox-wrapper">
                        <label class="switch">
                            <input type="checkbox" name="active" id="n_active" checked>
                            <span class="slider"></span>
                        </label>
                        <span class="switch-label">Active</span>
                    </div>
                </div>

            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal()">
                    <i class="fa-solid fa-xmark"></i> Cancel
                </button>
                <button type="submit" class="btn btn-primary">
                    <i class="fa-solid fa-check"></i>
                    <span id="submitBtnText">Save Notification</span>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- View Modal -->
<div id="viewModal" class="modal">
    <div class="modal-content modal-view">
        <div class="modal-header">
            <h3 class="modal-title">Notification Preview</h3>
            <button class="modal-close" onclick="closeViewModal()">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
        <div class="modal-body" id="viewModalBody"></div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeViewModal()">
                <i class="fa-solid fa-xmark"></i> Close
            </button>
        </div>
    </div>
</div>

<style>
/* ===================== ALERTS ===================== */
.alert {
    padding: 16px 20px; border-radius: 8px; margin-bottom: 24px;
    display: flex; align-items: center; gap: 12px;
    font-size: 13px; font-weight: 500;
    animation: slideDown 0.3s ease;
}
@keyframes slideDown {
    from { opacity: 0; transform: translateY(-10px); }
    to   { opacity: 1; transform: translateY(0); }
}
.alert i { font-size: 18px; }
.alert-success { background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; }
.alert-error   { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }

/* ===================== SUMMARY CARDS ===================== */
.summary-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
    margin-bottom: 28px;
}
.summary-card {
    background: #ffffff;
    border: 1px solid #e5e5e5;
    border-radius: 10px;
    padding: 20px;
    display: flex;
    align-items: center;
    gap: 16px;
    transition: box-shadow 0.2s;
}
.summary-card:hover { box-shadow: 0 4px 12px rgba(0,0,0,0.06); }
.summary-icon {
    width: 44px; height: 44px; border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 18px; flex-shrink: 0;
}
.icon-total     { background: #f0f0f0; color: #333333; }
.icon-live      { background: #f0fdf4; color: #16a34a; }
.icon-scheduled { background: #eff6ff; color: #2563eb; }
.icon-expired   { background: #fafafa; color: #999999; }
.summary-info   { display: flex; flex-direction: column; }
.summary-value  { font-size: 24px; font-weight: 700; color: #000000; line-height: 1; }
.summary-label  { font-size: 12px; color: #666666; margin-top: 4px; font-weight: 500; }

/* ===================== ACTION BAR ===================== */
.action-bar {
    display: flex; justify-content: space-between;
    align-items: flex-start; gap: 20px; margin-bottom: 24px;
    flex-wrap: wrap;
}
.search-filter-row { display: flex; gap: 10px; flex: 1; flex-wrap: wrap; }
.search-filter-row form { display: flex; gap: 10px; width: 100%; flex-wrap: wrap; }
.search-input-wrapper { position: relative; display: flex; align-items: center; flex: 1; min-width: 200px; }
.search-icon { position: absolute; left: 16px; color: #666666; font-size: 14px; pointer-events: none; }
.search-input {
    width: 100%; padding: 12px 16px 12px 44px;
    border: 1px solid #e5e5e5; border-radius: 8px;
    font-size: 14px; font-family: 'Inter', sans-serif;
    transition: all 0.3s; background: #ffffff;
}
.search-input:focus { outline: none; border-color: #000000; box-shadow: 0 0 0 3px rgba(0,0,0,0.05); }
.clear-search {
    position: absolute; right: 12px;
    background: #f0f0f0; border: none; width: 24px; height: 24px;
    border-radius: 50%; display: flex; align-items: center;
    justify-content: center; cursor: pointer; color: #666666; transition: all 0.2s;
}
.clear-search:hover { background: #e5e5e5; color: #000; }
.filter-select {
    padding: 12px 16px; border: 1px solid #e5e5e5; border-radius: 8px;
    font-size: 13px; font-family: 'Inter', sans-serif;
    background: #ffffff; color: #333333; cursor: pointer;
    transition: border-color 0.2s; min-width: 130px;
}
.filter-select:focus { outline: none; border-color: #000000; }

/* ===================== BUTTONS ===================== */
.btn {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 12px 24px; border: none; border-radius: 8px;
    font-size: 14px; font-weight: 600; cursor: pointer;
    transition: all 0.3s; text-decoration: none;
    font-family: 'Inter', sans-serif; white-space: nowrap;
}
.btn i { font-size: 14px; }
.btn-primary { background: #000000; color: #ffffff; }
.btn-primary:hover { background: #333333; transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.15); }
.btn-secondary { background: #f5f5f5; color: #333333; border: 1px solid #e5e5e5; }
.btn-secondary:hover { background: #e5e5e5; }

/* ===================== CARD ===================== */
.content-card { background: #ffffff; border: 1px solid #e5e5e5; border-radius: 10px; padding: 24px; }
.card-header-with-count { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
.card-title { font-size: 16px; font-weight: 700; color: #000000; }
.item-count {
    background: #fafafa; color: #666666; padding: 6px 12px;
    border-radius: 12px; font-size: 12px; font-weight: 600; border: 1px solid #e5e5e5;
}

/* ===================== TABLE ===================== */
.table-responsive { overflow-x: auto; margin-top: 8px; }
.data-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.data-table thead { background: #fafafa; border-bottom: 2px solid #e5e5e5; }
.data-table th {
    padding: 12px 16px; text-align: left; font-weight: 600;
    color: #333333; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px;
}
.data-table tbody tr { border-bottom: 1px solid #f0f0f0; transition: background 0.2s; }
.data-table tbody tr:hover { background: #fafafa; }
.data-table td { padding: 14px 16px; color: #333333; vertical-align: middle; }

.title-cell strong { display: block; font-size: 13px; color: #000; margin-bottom: 4px; }
.message-preview { font-size: 12px; color: #666666; margin: 0; line-height: 1.4; }
.date-text { font-size: 12px; font-weight: 500; color: #444; white-space: nowrap; }
.expired-date { color: #ef4444; }

/* ===================== TYPE BADGE ===================== */
.type-badge {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 4px 10px; border-radius: 6px;
    font-size: 11px; font-weight: 600; white-space: nowrap;
}
.type-info    { background: #eff6ff; color: #2563eb; border: 1px solid #bfdbfe; }
.type-success { background: #f0fdf4; color: #16a34a; border: 1px solid #bbf7d0; }
.type-warning { background: #fffbeb; color: #d97706; border: 1px solid #fde68a; }
.type-danger  { background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; }

/* ===================== COUNTDOWN ===================== */
.countdown-badge {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 4px 10px; border-radius: 12px;
    font-size: 11px; font-weight: 600; white-space: nowrap;
}
.countdown-live      { background: #f0fdf4; color: #16a34a; border: 1px solid #bbf7d0; }
.countdown-scheduled { background: #eff6ff; color: #2563eb; border: 1px solid #bfdbfe; }
.countdown-expired   { background: #fafafa; color: #999999; border: 1px solid #e5e5e5; }
.countdown-off       { background: #fafafa; color: #999999; border: 1px solid #e5e5e5; }

.pulse-dot {
    width: 7px; height: 7px; border-radius: 50%;
    background: #16a34a; display: inline-block;
    animation: pulse 1.5s ease-in-out infinite;
    flex-shrink: 0;
}
@keyframes pulse {
    0%, 100% { transform: scale(1); opacity: 1; }
    50%       { transform: scale(1.4); opacity: 0.6; }
}

/* ===================== STATUS BADGES ===================== */
.badge {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 4px 10px; border-radius: 12px;
    font-size: 11px; font-weight: 600;
}
.badge i { font-size: 10px; }
.badge-live      { background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; }
.badge-scheduled { background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; }
.badge-expired   { background: #fafafa; color: #999999; border: 1px solid #e5e5e5; }
.badge-inactive  { background: #fafafa; color: #666666; border: 1px solid #e5e5e5; }

/* ===================== ACTION BUTTONS ===================== */
.action-buttons { display: flex; gap: 6px; }
.btn-action {
    display: inline-flex; align-items: center; justify-content: center;
    width: 32px; height: 32px; border-radius: 6px;
    border: 1px solid #e5e5e5; background: #ffffff;
    color: #666666; cursor: pointer; transition: all 0.2s; text-decoration: none;
}
.btn-action:hover { transform: translateY(-2px); box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
.btn-view:hover   { background: #2563eb; color: #fff; border-color: #2563eb; }
.btn-edit:hover   { background: #000000; color: #fff; border-color: #000; }
.btn-toggle:hover { background: #16a34a; color: #fff; border-color: #16a34a; }
.btn-delete:hover { background: #ef4444; color: #fff; border-color: #ef4444; }

/* ===================== EMPTY STATE ===================== */
.empty-state { text-align: center; padding: 60px 20px; color: #666666; }
.empty-state i { font-size: 64px; color: #e5e5e5; margin-bottom: 20px; }
.empty-state h3 { font-size: 18px; font-weight: 600; color: #333333; margin-bottom: 8px; }
.empty-state p { font-size: 14px; margin-bottom: 0; }

/* ===================== MODAL ===================== */
.modal {
    display: none; position: fixed; top: 0; left: 0;
    width: 100%; height: 100%;
    background: rgba(0,0,0,0.5); z-index: 9999;
    align-items: center; justify-content: center;
}
.modal.active { display: flex; }
.modal-content {
    background: #ffffff; border-radius: 12px; width: 90%; max-width: 680px;
    max-height: 90vh; overflow-y: auto;
    box-shadow: 0 20px 60px rgba(0,0,0,0.3);
}
.modal-wide { max-width: 720px; }
.modal-view { max-width: 560px; }
.modal-header {
    padding: 24px; border-bottom: 1px solid #e5e5e5;
    display: flex; justify-content: space-between; align-items: center;
    position: sticky; top: 0; background: #fff; z-index: 1;
}
.modal-title { font-size: 18px; font-weight: 700; color: #000000; }
.modal-close {
    background: none; border: none; font-size: 20px; color: #666666;
    cursor: pointer; width: 32px; height: 32px; display: flex;
    align-items: center; justify-content: center; border-radius: 6px; transition: all 0.2s;
}
.modal-close:hover { background: #f0f0f0; color: #000; }
.modal-body  { padding: 24px; }
.modal-footer {
    padding: 20px 24px; border-top: 1px solid #e5e5e5;
    display: flex; justify-content: flex-end; gap: 12px;
}

/* ===================== FORM ===================== */
.form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
.form-group { margin-bottom: 20px; }
.form-label { display: block; font-size: 13px; font-weight: 600; margin-bottom: 8px; color: #333333; }
.required { color: #ef4444; }
.form-input {
    width: 100%; padding: 12px 16px; border: 1px solid #e5e5e5;
    border-radius: 8px; font-size: 14px; font-family: 'Inter', sans-serif;
    transition: all 0.3s; background: #ffffff; box-sizing: border-box;
}
.form-input:focus { outline: none; border-color: #000000; box-shadow: 0 0 0 3px rgba(0,0,0,0.05); }
.form-textarea { min-height: 100px; resize: vertical; }
.form-hint { display: block; font-size: 11px; color: #666666; margin-top: 6px; }

/* ===================== TYPE PILLS ===================== */
.type-pills { display: flex; gap: 10px; flex-wrap: wrap; }
.type-pill {
    display: flex; align-items: center; gap: 6px;
    padding: 8px 16px; border-radius: 8px; border: 2px solid #e5e5e5;
    font-size: 13px; font-weight: 600; cursor: pointer;
    transition: all 0.2s; background: #fafafa; color: #666;
}
.type-pill input[type="radio"] { display: none; }
.type-pill:hover { border-color: #aaa; }
.type-pill.selected.pill-info    { border-color: #2563eb; background: #eff6ff; color: #2563eb; }
.type-pill.selected.pill-success { border-color: #16a34a; background: #f0fdf4; color: #16a34a; }
.type-pill.selected.pill-warning { border-color: #d97706; background: #fffbeb; color: #d97706; }
.type-pill.selected.pill-danger  { border-color: #dc2626; background: #fef2f2; color: #dc2626; }

/* Duration preview */
.duration-preview {
    display: flex; align-items: center; gap: 10px;
    padding: 12px 16px; background: #f8f8f8; border: 1px solid #e5e5e5;
    border-radius: 8px; font-size: 13px; color: #333333; margin-bottom: 20px;
}
.duration-preview i { color: #666666; }

/* ===================== SWITCH ===================== */
.checkbox-wrapper { display: flex; align-items: center; gap: 12px; }
.switch { position: relative; display: inline-block; width: 48px; height: 24px; }
.switch input { opacity: 0; width: 0; height: 0; }
.slider {
    position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0;
    background-color: #e5e5e5; transition: 0.3s; border-radius: 24px;
}
.slider:before {
    position: absolute; content: ""; height: 18px; width: 18px;
    left: 3px; bottom: 3px; background-color: white;
    transition: 0.3s; border-radius: 50%;
}
input:checked + .slider { background-color: #000000; }
input:checked + .slider:before { transform: translateX(24px); }
.switch-label { font-size: 14px; font-weight: 500; color: #333333; }

/* ===================== VIEW PREVIEW ===================== */
.preview-notification {
    border-radius: 10px; padding: 20px;
    border: 1px solid; margin-bottom: 20px;
}
.preview-info    { background: #eff6ff; border-color: #bfdbfe; }
.preview-success { background: #f0fdf4; border-color: #bbf7d0; }
.preview-warning { background: #fffbeb; border-color: #fde68a; }
.preview-danger  { background: #fef2f2; border-color: #fecaca; }
.preview-header  { display: flex; align-items: center; gap: 10px; margin-bottom: 10px; }
.preview-icon    { font-size: 20px; }
.preview-info .preview-icon    { color: #2563eb; }
.preview-success .preview-icon { color: #16a34a; }
.preview-warning .preview-icon { color: #d97706; }
.preview-danger .preview-icon  { color: #dc2626; }
.preview-title {
    font-size: 15px; font-weight: 700;
}
.preview-info .preview-title    { color: #1e40af; }
.preview-success .preview-title { color: #166534; }
.preview-warning .preview-title { color: #92400e; }
.preview-danger .preview-title  { color: #991b1b; }
.preview-message { font-size: 13px; line-height: 1.6; }
.preview-info .preview-message    { color: #1e40af; }
.preview-success .preview-message { color: #166534; }
.preview-warning .preview-message { color: #92400e; }
.preview-danger .preview-message  { color: #991b1b; }
.preview-meta {
    display: grid; grid-template-columns: 1fr 1fr;
    gap: 12px; margin-top: 16px; padding-top: 16px; border-top: 1px solid rgba(0,0,0,0.08);
}
.preview-meta-item label { display: block; font-size: 11px; color: #666; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px; }
.preview-meta-item span  { font-size: 13px; font-weight: 500; color: #333; }

/* ===================== RESPONSIVE ===================== */
@media (max-width: 1024px) {
    .summary-grid { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 768px) {
    .summary-grid { grid-template-columns: repeat(2, 1fr); }
    .action-bar { flex-direction: column; }
    .search-filter-row form { flex-direction: column; }
    .filter-select { min-width: unset; width: 100%; }
    .btn { width: 100%; justify-content: center; }
    .form-row { grid-template-columns: 1fr; }
    .modal-content { width: 95%; }
    .modal-header, .modal-body, .modal-footer { padding: 16px; }
    .modal-footer { flex-direction: column; }
    .modal-footer .btn { width: 100%; }
    .preview-meta { grid-template-columns: 1fr; }
    .type-pills { flex-direction: column; }
    .type-pill { width: 100%; justify-content: center; }
    .data-table { font-size: 12px; }
}
</style>

<script>
const notificationsData = <?php
    $allN = mysqli_query($conn, "SELECT * FROM notifications ORDER BY id");
    $arr  = [];
    while ($r = mysqli_fetch_assoc($allN)) $arr[] = $r;
    echo json_encode($arr);
?>;

// ---- Type pills ----
document.querySelectorAll('.type-pill').forEach(pill => {
    const radio = pill.querySelector('input[type="radio"]');
    if (radio.checked) pill.classList.add('selected');
    pill.addEventListener('click', () => {
        document.querySelectorAll('.type-pill').forEach(p => p.classList.remove('selected'));
        pill.classList.add('selected');
    });
});

// ---- Duration preview ----
function updateDuration() {
    const from  = document.getElementById('n_valid_from').value;
    const until = document.getElementById('n_valid_until').value;
    const preview = document.getElementById('durationPreview');
    if (!from || !until) { preview.style.display = 'none'; return; }
    const diff = (new Date(until) - new Date(from)) / 1000;
    if (diff <= 0) {
        document.getElementById('durationText').textContent = '⚠ Valid Until must be after Valid From';
        preview.style.display = 'flex';
        return;
    }
    const days = Math.floor(diff / 86400);
    const hrs  = Math.floor((diff % 86400) / 3600);
    const mins = Math.floor((diff % 3600) / 60);
    let str = 'Active duration: ';
    if (days > 0) str += `${days} day${days>1?'s':''} `;
    if (hrs  > 0) str += `${hrs} hour${hrs>1?'s':''} `;
    if (mins > 0) str += `${mins} minute${mins>1?'s':''}`;
    document.getElementById('durationText').textContent = str.trim();
    preview.style.display = 'flex';
}
document.getElementById('n_valid_from').addEventListener('change', updateDuration);
document.getElementById('n_valid_until').addEventListener('change', updateDuration);

// ---- Modal open/close ----
function openModal() {
    document.getElementById('notificationModal').classList.add('active');
    document.getElementById('notificationForm').reset();
    document.getElementById('notification_id').value = '';
    document.getElementById('modalTitle').textContent = 'Add Notification';
    document.getElementById('submitBtnText').textContent = 'Save Notification';
    document.getElementById('n_active').checked = true;
    document.getElementById('durationPreview').style.display = 'none';
    document.querySelectorAll('.type-pill').forEach(p => p.classList.remove('selected'));
    document.querySelector('.type-pill.pill-info').classList.add('selected');
    document.querySelector('.type-pill.pill-info input').checked = true;
}
function closeModal() { document.getElementById('notificationModal').classList.remove('active'); }

// ---- Edit ----
function editNotification(id) {
    const n = notificationsData.find(x => x.id == id);
    if (!n) return;
    openModal();
    document.getElementById('notification_id').value = n.id;
    document.getElementById('n_title').value   = n.title;
    document.getElementById('n_message').value = n.message;
    document.getElementById('n_valid_from').value  = n.valid_from.replace(' ', 'T').slice(0,16);
    document.getElementById('n_valid_until').value = n.valid_until.replace(' ', 'T').slice(0,16);
    document.getElementById('n_active').checked    = n.active == 1;
    document.getElementById('modalTitle').textContent    = 'Edit Notification';
    document.getElementById('submitBtnText').textContent = 'Update Notification';

    // Set type pill
    document.querySelectorAll('.type-pill').forEach(p => {
        p.classList.remove('selected');
        if (p.querySelector('input').value === n.type) {
            p.classList.add('selected');
            p.querySelector('input').checked = true;
        }
    });
    updateDuration();
}

// ---- View ----
function viewNotification(id) {
    const n = notificationsData.find(x => x.id == id);
    if (!n) return;
    const iconMap = { info: 'fa-circle-info', success: 'fa-circle-check', warning: 'fa-triangle-exclamation', danger: 'fa-circle-exclamation' };
    const now = Date.now();
    const fromMs  = new Date(n.valid_from).getTime();
    const untilMs = new Date(n.valid_until).getTime();
    let statusLabel = 'Inactive';
    if (now >= fromMs && now <= untilMs && n.active == 1) statusLabel = '🟢 Live Now';
    else if (now < fromMs && n.active == 1) statusLabel = '🔵 Scheduled';
    else if (now > untilMs) statusLabel = '⚫ Expired';

    document.getElementById('viewModalBody').innerHTML = `
        <div class="preview-notification preview-${n.type}">
            <div class="preview-header">
                <i class="fa-solid ${iconMap[n.type]} preview-icon"></i>
                <span class="preview-title">${escHtml(n.title)}</span>
            </div>
            <p class="preview-message">${escHtml(n.message)}</p>
            <div class="preview-meta">
                <div class="preview-meta-item">
                    <label>Valid From</label>
                    <span>${formatDT(n.valid_from)}</span>
                </div>
                <div class="preview-meta-item">
                    <label>Valid Until</label>
                    <span>${formatDT(n.valid_until)}</span>
                </div>
                <div class="preview-meta-item">
                    <label>Type</label>
                    <span>${n.type.charAt(0).toUpperCase() + n.type.slice(1)}</span>
                </div>
                <div class="preview-meta-item">
                    <label>Display Status</label>
                    <span>${statusLabel}</span>
                </div>
            </div>
        </div>`;
    document.getElementById('viewModal').classList.add('active');
}
function closeViewModal() { document.getElementById('viewModal').classList.remove('active'); }

function escHtml(t) {
    const d = document.createElement('div');
    d.appendChild(document.createTextNode(t));
    return d.innerHTML;
}
function formatDT(dt) {
    const d = new Date(dt);
    return d.toLocaleDateString('en-GB', { day:'2-digit', month:'short', year:'numeric' })
         + ' ' + d.toLocaleTimeString('en-GB', { hour:'2-digit', minute:'2-digit' });
}

// Close modals on outside click
document.getElementById('notificationModal').addEventListener('click', function(e) { if (e.target === this) closeModal(); });
document.getElementById('viewModal').addEventListener('click', function(e) { if (e.target === this) closeViewModal(); });

// Search debounce
let searchTimeout;
document.getElementById('searchInput').addEventListener('input', function() {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => document.getElementById('searchForm').submit(), 500);
});

function clearSearch() {
    document.getElementById('searchInput').value = '';
    document.getElementById('searchForm').submit();
}

// Form submit loading
document.getElementById('notificationForm').addEventListener('submit', function() {
    const btn = this.querySelector('.modal-footer .btn-primary');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving...';
});
</script>

<?php include 'footer.php'; ?>
