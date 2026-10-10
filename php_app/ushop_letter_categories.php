<?php
include 'config.php';

/* ═══════════════════════════════════════════════════════
   AUTO-CREATE TABLES
═══════════════════════════════════════════════════════ */
mysqli_query($conn, "
    CREATE TABLE IF NOT EXISTS `ushop_letter_categories` (
      `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
      `name`       VARCHAR(191) NOT NULL,
      `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      UNIQUE KEY `uq_name` (`name`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
mysqli_query($conn, "
    CREATE TABLE IF NOT EXISTS `ushop_letter_category_customers` (
      `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
      `category_id`   INT UNSIGNED NOT NULL,
      `customer_code` VARCHAR(100) NOT NULL,
      `customer_name` VARCHAR(191) NOT NULL DEFAULT '',
      PRIMARY KEY (`id`),
      UNIQUE KEY `uq_cat_cust` (`category_id`, `customer_code`),
      CONSTRAINT `fk_lcc_category`
        FOREIGN KEY (`category_id`)
        REFERENCES `ushop_letter_categories` (`id`)
        ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
mysqli_query($conn, "
    CREATE TABLE IF NOT EXISTS `ushop_letter_subcategories` (
      `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
      `category_id` INT UNSIGNED NOT NULL,
      `name`        VARCHAR(191) NOT NULL,
      `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      CONSTRAINT `fk_sub_category`
        FOREIGN KEY (`category_id`)
        REFERENCES `ushop_letter_categories` (`id`)
        ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
mysqli_query($conn, "
    CREATE TABLE IF NOT EXISTS `ushop_letter_subcategory_customers` (
      `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
      `subcategory_id`  INT UNSIGNED NOT NULL,
      `customer_code`   VARCHAR(100) NOT NULL,
      `customer_name`   VARCHAR(191) NOT NULL DEFAULT '',
      PRIMARY KEY (`id`),
      UNIQUE KEY `uq_sub_cust` (`subcategory_id`, `customer_code`),
      CONSTRAINT `fk_subc_sub`
        FOREIGN KEY (`subcategory_id`)
        REFERENCES `ushop_letter_subcategories` (`id`)
        ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
/* Add customer_name column if upgrading from old schema */
mysqli_query($conn, "ALTER TABLE ushop_letter_category_customers ADD COLUMN IF NOT EXISTS customer_name VARCHAR(191) NOT NULL DEFAULT '' AFTER customer_code");
mysqli_query($conn, "ALTER TABLE ushop_letter_subcategory_customers ADD COLUMN IF NOT EXISTS customer_name VARCHAR(191) NOT NULL DEFAULT '' AFTER customer_code");

/* ═══════════════════════════════════════════════════════
   HELPERS
═══════════════════════════════════════════════════════ */
function parseCustomerVal($raw) {
    $parts = explode('|||', $raw, 2);
    return [
        'code' => trim($parts[0]),
        'name' => trim($parts[1] ?? ''),
    ];
}

/* ═══════════════════════════════════════════════════════
   CRUD ACTIONS
═══════════════════════════════════════════════════════ */
$action  = $_POST['action'] ?? '';
$success = '';
$error   = '';

/* ── Main category create ── */
if ($action === 'create') {
    $name = trim($_POST['cat_name'] ?? '');
    $ids  = $_POST['customer_ids'] ?? [];
    if ($name === '') { $error = 'Category name is required.'; }
    else {
        $ne = mysqli_real_escape_string($conn, $name);
        mysqli_query($conn, "INSERT INTO ushop_letter_categories (name, created_at) VALUES ('$ne', NOW())");
        $cat_id = mysqli_insert_id($conn);
        foreach ($ids as $raw) {
            $p = parseCustomerVal($raw);
            $ce = mysqli_real_escape_string($conn, $p['code']);
            $cn = mysqli_real_escape_string($conn, $p['name']);
            mysqli_query($conn, "INSERT IGNORE INTO ushop_letter_category_customers (category_id, customer_code, customer_name) VALUES ($cat_id, '$ce', '$cn')");
        }
        $success = 'Category <strong>'.htmlspecialchars($name).'</strong> created successfully.';
    }
}

/* ── Main category edit ── */
if ($action === 'edit') {
    $cat_id = intval($_POST['cat_id'] ?? 0);
    $name   = trim($_POST['cat_name'] ?? '');
    $ids    = $_POST['customer_ids'] ?? [];
    if ($cat_id && $name !== '') {
        $ne = mysqli_real_escape_string($conn, $name);
        mysqli_query($conn, "UPDATE ushop_letter_categories SET name='$ne' WHERE id=$cat_id");
        mysqli_query($conn, "DELETE FROM ushop_letter_category_customers WHERE category_id=$cat_id");
        foreach ($ids as $raw) {
            $p = parseCustomerVal($raw);
            $ce = mysqli_real_escape_string($conn, $p['code']);
            $cn = mysqli_real_escape_string($conn, $p['name']);
            mysqli_query($conn, "INSERT IGNORE INTO ushop_letter_category_customers (category_id, customer_code, customer_name) VALUES ($cat_id, '$ce', '$cn')");
        }
        $success = 'Category updated successfully.';
    } else { $error = 'Category name is required.'; }
}

/* ── Main category delete ── */
if ($action === 'delete') {
    $cat_id = intval($_POST['cat_id'] ?? 0);
    if ($cat_id) {
        mysqli_query($conn, "DELETE FROM ushop_letter_category_customers WHERE category_id=$cat_id");
        mysqli_query($conn, "DELETE FROM ushop_letter_categories WHERE id=$cat_id");
        $success = 'Category deleted.';
    }
}

/* ── Sub-category create ── */
if ($action === 'sub_create') {
    $cat_id = intval($_POST['cat_id'] ?? 0);
    $name   = trim($_POST['sub_name'] ?? '');
    $ids    = $_POST['sub_customer_ids'] ?? [];
    if (!$cat_id || $name === '') { $error = 'Sub-category name is required.'; }
    else {
        $ne = mysqli_real_escape_string($conn, $name);
        mysqli_query($conn, "INSERT INTO ushop_letter_subcategories (category_id, name, created_at) VALUES ($cat_id, '$ne', NOW())");
        $sub_id = mysqli_insert_id($conn);
        foreach ($ids as $raw) {
            $p = parseCustomerVal($raw);
            $ce = mysqli_real_escape_string($conn, $p['code']);
            $cn = mysqli_real_escape_string($conn, $p['name']);
            mysqli_query($conn, "INSERT IGNORE INTO ushop_letter_subcategory_customers (subcategory_id, customer_code, customer_name) VALUES ($sub_id, '$ce', '$cn')");
        }
        $success = 'Sub-category <strong>'.htmlspecialchars($name).'</strong> created.';
    }
}

/* ── Sub-category edit ── */
if ($action === 'sub_edit') {
    $sub_id = intval($_POST['sub_id'] ?? 0);
    $name   = trim($_POST['sub_name'] ?? '');
    $ids    = $_POST['sub_customer_ids'] ?? [];
    if ($sub_id && $name !== '') {
        $ne = mysqli_real_escape_string($conn, $name);
        mysqli_query($conn, "UPDATE ushop_letter_subcategories SET name='$ne' WHERE id=$sub_id");
        mysqli_query($conn, "DELETE FROM ushop_letter_subcategory_customers WHERE subcategory_id=$sub_id");
        foreach ($ids as $raw) {
            $p = parseCustomerVal($raw);
            $ce = mysqli_real_escape_string($conn, $p['code']);
            $cn = mysqli_real_escape_string($conn, $p['name']);
            mysqli_query($conn, "INSERT IGNORE INTO ushop_letter_subcategory_customers (subcategory_id, customer_code, customer_name) VALUES ($sub_id, '$ce', '$cn')");
        }
        $success = 'Sub-category updated.';
    } else { $error = 'Sub-category name is required.'; }
}

/* ── Sub-category delete ── */
if ($action === 'sub_delete') {
    $sub_id = intval($_POST['sub_id'] ?? 0);
    if ($sub_id) {
        mysqli_query($conn, "DELETE FROM ushop_letter_subcategory_customers WHERE subcategory_id=$sub_id");
        mysqli_query($conn, "DELETE FROM ushop_letter_subcategories WHERE id=$sub_id");
        $success = 'Sub-category deleted.';
    }
}

/* ═══════════════════════════════════════════════════════
   DATA LOADS
═══════════════════════════════════════════════════════ */
/* All unique customers from invoices */
$cust_rows = mysqli_query($conn, "
    SELECT DISTINCT customer_code, customer_name
    FROM ushop_invoices
    WHERE customer_code IS NOT NULL AND customer_code != ''
    ORDER BY customer_name ASC
");
$all_customers = [];
while ($c = mysqli_fetch_assoc($cust_rows)) $all_customers[] = $c;

/* Main categories */
$cats = mysqli_query($conn, "
    SELECT lc.id, lc.name, lc.created_at,
           COUNT(DISTINCT lcc.customer_code) AS cust_count,
           COUNT(DISTINCT ls.id) AS sub_count
    FROM ushop_letter_categories lc
    LEFT JOIN ushop_letter_category_customers lcc ON lcc.category_id = lc.id
    LEFT JOIN ushop_letter_subcategories ls ON ls.category_id = lc.id
    GROUP BY lc.id
    ORDER BY lc.created_at DESC
");

/* Edit data for main category */
$edit_data = null;
if (isset($_GET['edit'])) {
    $eid = intval($_GET['edit']);
    $er  = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM ushop_letter_categories WHERE id=$eid"));
    if ($er) {
        $edit_data = $er;
        $edit_data['members'] = []; // codes only, for in_array check
        $edit_data['member_vals'] = []; // code|||name, for select2 pre-selection
        $em = mysqli_query($conn, "SELECT customer_code, customer_name FROM ushop_letter_category_customers WHERE category_id=$eid");
        while ($row = mysqli_fetch_assoc($em)) {
            $edit_data['members'][] = $row['customer_code'];
            $edit_data['member_vals'][] = $row['customer_code'].'|||'.$row['customer_name'];
        }
    }
}

include 'header.php';
?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js"></script>
<style>
*{box-sizing:border-box;}
.page-wrap{max-width:1200px;margin:0 auto;padding:0 8px;}
.breadcrumb{display:flex;align-items:center;gap:6px;font-size:11.5px;color:#9ca3af;margin-bottom:14px;flex-wrap:wrap;}
.breadcrumb a{color:#0e7490;text-decoration:none;font-weight:600;}.breadcrumb a:hover{text-decoration:underline;}
.breadcrumb .sep{color:#d1d5db;}
.ph-row{display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:10px;margin-bottom:18px;}
.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border:none;border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;transition:all .18s;white-space:nowrap;text-decoration:none;}
.btn-teal{background:#0e7490;color:#fff;}.btn-teal:hover{background:#155e75;}
.btn-purple{background:#7c3aed;color:#fff;}.btn-purple:hover{background:#6d28d9;}
.btn-secondary{background:#f5f5f5;color:#374151;border:1px solid #e5e5e5;}.btn-secondary:hover{background:#e8e8e8;}
.btn-danger{background:#dc2626;color:#fff;}.btn-danger:hover{background:#b91c1c;}
.btn-warning{background:#d97706;color:#fff;}.btn-warning:hover{background:#b45309;}
.btn-sm{padding:5px 10px;font-size:11.5px;}
.alert{padding:12px 16px;border-radius:8px;font-size:13px;font-weight:600;margin-bottom:18px;display:flex;align-items:center;gap:8px;}
.alert-success{background:#dcfce7;color:#15803d;border:1px solid #bbf7d0;}
.alert-error{background:#fee2e2;color:#b91c1c;border:1px solid #fecaca;}

/* Form cards */
.form-card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;box-shadow:0 1px 6px rgba(0,0,0,.05);margin-bottom:24px;overflow:hidden;}
.form-card-header{padding:14px 20px;border-bottom:1px solid #f3f4f6;background:#f9fafb;display:flex;align-items:center;gap:8px;}
.form-card-title{font-size:14px;font-weight:700;color:#111827;}
.form-card-body{padding:20px;}
.form-row{display:flex;flex-wrap:wrap;gap:16px;align-items:flex-start;}
.form-group{display:flex;flex-direction:column;gap:5px;flex:1;min-width:200px;}
.form-group label{font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.4px;}
.form-group input[type=text]{padding:9px 12px;border:1px solid #d1d5db;border-radius:7px;font-size:13px;font-family:inherit;color:#111827;outline:none;transition:border .15s;}
.form-group input[type=text]:focus{border-color:#0e7490;box-shadow:0 0 0 3px rgba(14,116,144,.08);}
.form-group .select2-container{width:100%!important;}
.form-group .select2-container--default .select2-selection--multiple{border:1px solid #d1d5db;border-radius:7px;min-height:40px;padding:2px 6px;font-family:inherit;}
.form-group .select2-container--default.select2-container--focus .select2-selection--multiple{border-color:#0e7490;box-shadow:0 0 0 3px rgba(14,116,144,.08);}
.form-group .select2-container--default .select2-selection--multiple .select2-selection__choice{background:#cffafe;color:#0e7490;border:none;border-radius:5px;padding:2px 8px;font-size:11.5px;font-weight:700;}
.form-group .select2-container--default .select2-selection--multiple .select2-selection__choice__remove{color:#0e7490;margin-right:4px;}
.select2-dropdown{border:1px solid #d1d5db;border-radius:7px;font-size:12.5px;font-family:inherit;}
.select2-container--default .select2-results__option--highlighted[aria-selected]{background:#0e7490;}
.form-actions{margin-top:16px;display:flex;gap:8px;flex-wrap:wrap;}

/* Main table */
.table-card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;box-shadow:0 1px 6px rgba(0,0,0,.05);}
.table-toolbar{display:flex;justify-content:space-between;align-items:center;padding:14px 18px;border-bottom:1px solid #f3f4f6;flex-wrap:wrap;gap:8px;}
.tbl-title{font-size:14px;font-weight:700;color:#111827;}
.dt-wrap{overflow-x:auto;}
.data-table{width:100%;border-collapse:collapse;font-size:13px;}
.data-table th{padding:10px 14px;text-align:left;background:#f9fafb;border-bottom:2px solid #e5e7eb;color:#374151;font-size:11px;text-transform:uppercase;white-space:nowrap;}
.data-table td{padding:11px 14px;border-bottom:1px solid #f3f4f6;color:#111827;vertical-align:middle;}
.data-table tr:last-child td{border-bottom:none;}
.data-table tr.cat-row:hover td{background:#f9fafb;}
.badge-count{background:#e0f2fe;color:#075985;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:700;display:inline-flex;align-items:center;gap:4px;}
.badge-sub{background:#ede9fe;color:#7c3aed;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:700;display:inline-flex;align-items:center;gap:4px;}
.chip-list{display:flex;flex-wrap:wrap;gap:4px;}
.chip{background:#f3f4f6;color:#374151;padding:2px 8px;border-radius:5px;font-size:11px;font-weight:600;}

/* Expand chevron */
.ch-btn{cursor:pointer;background:none;border:none;color:#9ca3af;padding:2px 4px;line-height:1;}
.ch-btn i{transition:transform .22s;display:inline-block;}
.ch-btn.open i{transform:rotate(90deg);}

/* Sub-category expansion row */
.sub-expand-row{display:none;}
.sub-expand-row.open{display:table-row;}
.sub-expand-cell{padding:0!important;background:#f8fafc;border-bottom:2px solid #e5e7eb!important;}
.sub-inner{padding:0 0 14px 48px;}
.sub-header{display:flex;justify-content:space-between;align-items:center;padding:12px 16px 8px 0;}
.sub-header-title{font-size:12px;font-weight:700;color:#7c3aed;text-transform:uppercase;letter-spacing:.4px;}

/* Sub-category table */
.sub-table{width:100%;border-collapse:collapse;font-size:12px;}
.sub-table th{padding:7px 12px;text-align:left;background:#ede9fe;color:#7c3aed;font-size:10.5px;text-transform:uppercase;font-weight:700;border-bottom:1px solid #ddd6fe;}
.sub-table td{padding:8px 12px;border-bottom:1px solid #f0eeff;color:#374151;vertical-align:middle;}
.sub-table tr:last-child td{border-bottom:none;}
.sub-table tr:hover td{background:#faf9ff;}
.sub-empty{padding:16px 12px;font-size:12px;color:#9ca3af;text-align:center;}

/* Modals */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9000;align-items:center;justify-content:center;}
.modal-overlay.open{display:flex;}
.modal-box{background:#fff;border-radius:12px;padding:0;max-width:560px;width:92%;box-shadow:0 8px 40px rgba(0,0,0,.18);overflow:hidden;}
.modal-head{padding:16px 22px;border-bottom:1px solid #f3f4f6;display:flex;align-items:center;gap:8px;}
.modal-head-title{font-size:15px;font-weight:700;color:#111827;flex:1;}
.modal-body{padding:20px 22px;}
.modal-foot{padding:14px 22px;border-top:1px solid #f3f4f6;display:flex;justify-content:flex-end;gap:8px;background:#f9fafb;}

/* Delete confirm modal */
.del-modal-box{background:#fff;border-radius:12px;padding:28px 28px 24px;max-width:400px;width:90%;box-shadow:0 8px 40px rgba(0,0,0,.18);}
.del-modal-box h3{margin:0 0 10px;font-size:16px;color:#111827;}
.del-modal-box p{font-size:13px;color:#6b7280;margin:0 0 20px;}
.modal-actions{display:flex;gap:8px;justify-content:flex-end;}

@media(max-width:640px){.form-row{flex-direction:column;}.form-group{min-width:100%;}}
</style>

<div class="page-wrap">

<div class="breadcrumb">
  <a href="dashboard.php"><i class="fa-solid fa-house"></i> Dashboard</a>
  <span class="sep">›</span>
  <a href="ushop_invoice_list.php"><i class="fa-solid fa-receipt"></i> Invoices</a>
  <span class="sep">›</span>
  <span style="color:#0e7490;font-weight:700;"><i class="fa-solid fa-tags"></i> Letter Categories</span>
</div>

<div class="ph-row">
  <div>
    <h2 style="margin:0;font-size:19px;font-weight:700;color:#111827;">
      <i class="fa-solid fa-tags" style="color:#0e7490;margin-right:8px;"></i>Letter Categories
    </h2>
    <p style="margin:4px 0 0;font-size:12px;color:#6b7280;">Group customers into categories and sub-categories for letter generation.</p>
  </div>
  <a href="ushop_invoice_list.php" class="btn btn-secondary"><i class="fa-solid fa-receipt"></i> Invoices</a>
</div>

<?php if($success): ?><div class="alert alert-success"><i class="fa-solid fa-circle-check"></i> <?= $success ?></div><?php endif; ?>
<?php if($error):   ?><div class="alert alert-error"><i class="fa-solid fa-circle-exclamation"></i> <?= htmlspecialchars($error) ?></div><?php endif; ?>

<!-- ══ CREATE FORM ══ -->
<?php if(!$edit_data): ?>
<div class="form-card">
  <div class="form-card-header">
    <i class="fa-solid fa-folder-plus" style="color:#0e7490;"></i>
    <span class="form-card-title">New Category</span>
  </div>
  <div class="form-card-body">
    <form method="POST" action="">
      <input type="hidden" name="action" value="create">
      <div class="form-row">
        <div class="form-group" style="max-width:320px;">
          <label><i class="fa-solid fa-tag"></i> Category Name</label>
          <input type="text" name="cat_name" placeholder="e.g. VIP Members, North Region…" required>
        </div>
        <div class="form-group" style="flex:2;min-width:280px;">
          <label><i class="fa-solid fa-users"></i> Customers (from all invoices)</label>
          <label style="display:flex;align-items:center;gap:6px;margin-bottom:6px;cursor:pointer;user-select:none;">
            <input type="checkbox" id="noCustCreate" onchange="toggleNoCust('custSelect','noCustCreate')"
                   style="width:15px;height:15px;accent-color:#0e7490;cursor:pointer;">
            <span style="font-size:12px;color:#6b7280;font-weight:600;text-transform:none;letter-spacing:0;">No customer selection (empty category)</span>
          </label>
          <div id="custSelectWrap">
            <select name="customer_ids[]" id="custSelect" multiple="multiple" class="customer-select">
              <?php foreach($all_customers as $c): ?>
              <option value="<?= htmlspecialchars($c['customer_code'].'|||'.($c['customer_name']??'')) ?>">
                <?= htmlspecialchars($c['customer_name']?:'Unknown') ?> — <?= htmlspecialchars($c['customer_code']) ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
      </div>
      <div class="form-actions">
        <button type="submit" class="btn btn-teal"><i class="fa-solid fa-plus"></i> Create Category</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<!-- ══ EDIT FORM ══ -->
<?php if($edit_data): ?>
<div class="form-card" style="border-top:3px solid #d97706;">
  <div class="form-card-header" style="background:#fffbeb;">
    <i class="fa-solid fa-pen-to-square" style="color:#d97706;"></i>
    <span class="form-card-title" style="color:#92400e;">Edit Category: <?= htmlspecialchars($edit_data['name']) ?></span>
  </div>
  <div class="form-card-body">
    <form method="POST" action="">
      <input type="hidden" name="action" value="edit">
      <input type="hidden" name="cat_id" value="<?= $edit_data['id'] ?>">
      <div class="form-row">
        <div class="form-group" style="max-width:320px;">
          <label><i class="fa-solid fa-tag"></i> Category Name</label>
          <input type="text" name="cat_name" value="<?= htmlspecialchars($edit_data['name']) ?>" required>
        </div>
        <div class="form-group" style="flex:2;min-width:280px;">
          <label><i class="fa-solid fa-users"></i> Customers (from all invoices)</label>
          <label style="display:flex;align-items:center;gap:6px;margin-bottom:6px;cursor:pointer;user-select:none;">
            <input type="checkbox" id="noCustEdit" onchange="toggleNoCust('custSelectEdit','noCustEdit')"
                   <?php if(empty($edit_data['members'])): ?>checked<?php endif; ?>
                   style="width:15px;height:15px;accent-color:#0e7490;cursor:pointer;">
            <span style="font-size:12px;color:#6b7280;font-weight:600;text-transform:none;letter-spacing:0;">No customer selection (empty category)</span>
          </label>
          <div id="custSelectEditWrap" <?php if(empty($edit_data['members'])): ?>style="display:none;"<?php endif; ?>>
            <select name="customer_ids[]" id="custSelectEdit" multiple="multiple" class="customer-select">
              <?php foreach($all_customers as $c):
                $val = $c['customer_code'].'|||'.($c['customer_name']??'');
                $sel = in_array($c['customer_code'], $edit_data['members']) ? 'selected' : '';
              ?>
              <option value="<?= htmlspecialchars($val) ?>" <?= $sel ?>>
                <?= htmlspecialchars($c['customer_name']?:'Unknown') ?> — <?= htmlspecialchars($c['customer_code']) ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
      </div>
      <div class="form-actions">
        <button type="submit" class="btn btn-warning"><i class="fa-solid fa-floppy-disk"></i> Save Changes</button>
        <a href="ushop_letter_categories.php" class="btn btn-secondary"><i class="fa-solid fa-xmark"></i> Cancel</a>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<!-- ══ CATEGORIES TABLE ══ -->
<div class="table-card">
  <div class="table-toolbar">
    <div class="tbl-title"><i class="fa-solid fa-tags" style="margin-right:6px;color:#0e7490;"></i>All Categories</div>
    <span style="font-size:11.5px;color:#6b7280;"><?= mysqli_num_rows($cats) ?> categor<?= mysqli_num_rows($cats)===1?'y':'ies' ?></span>
  </div>
  <div class="dt-wrap">
  <table class="data-table">
    <thead>
      <tr>
        <th style="width:32px;"></th>
        <th>#</th>
        <th>Category Name</th>
        <th>Customers</th>
        <th>Sub-categories</th>
        <th>Members</th>
        <th>Created</th>
        <th style="text-align:center;">Actions</th>
      </tr>
    </thead>
    <tbody>
    <?php
    mysqli_data_seek($cats, 0);
    $i = 1;
    while ($cat = mysqli_fetch_assoc($cats)):
      /* Members — read stored name directly, no JOIN needed */
      $mem_q = mysqli_query($conn, "
          SELECT customer_code, customer_name
          FROM ushop_letter_category_customers
          WHERE category_id = {$cat['id']}
          ORDER BY customer_name ASC
      ");
      $members = [];
      while($m = mysqli_fetch_assoc($mem_q)) $members[] = $m;

      /* Sub-categories */
      $sub_q = mysqli_query($conn, "
          SELECT ls.*, COUNT(lsc.customer_code) AS scust_count
          FROM ushop_letter_subcategories ls
          LEFT JOIN ushop_letter_subcategory_customers lsc ON lsc.subcategory_id = ls.id
          WHERE ls.category_id = {$cat['id']}
          GROUP BY ls.id
          ORDER BY ls.created_at ASC
      ");
      $subs = [];
      while($s = mysqli_fetch_assoc($sub_q)) $subs[] = $s;
    ?>
    <!-- Main row -->
    <tr class="cat-row">
      <td style="padding:11px 6px 11px 14px;">
        <button class="ch-btn" id="chBtn<?= $cat['id'] ?>" onclick="toggleSub(<?= $cat['id'] ?>)" title="Show sub-categories">
          <i class="fa-solid fa-chevron-right"></i>
        </button>
      </td>
      <td style="color:#9ca3af;font-size:11px;"><?= $i++ ?></td>
      <td><span style="font-weight:700;color:#111827;font-size:13.5px;"><?= htmlspecialchars($cat['name']) ?></span></td>
      <td><span class="badge-count"><i class="fa-solid fa-user" style="font-size:10px;"></i><?= $cat['cust_count'] ?></span></td>
      <td><span class="badge-sub"><i class="fa-solid fa-sitemap" style="font-size:10px;"></i><?= $cat['sub_count'] ?></span></td>
      <td style="max-width:340px;">
        <?php if($members): ?>
        <div class="chip-list">
          <?php foreach(array_slice($members,0,6) as $m): ?>
          <span class="chip" title="<?= htmlspecialchars($m['customer_code']) ?>"><?= htmlspecialchars($m['customer_name'] ?: $m['customer_code']) ?></span>
          <?php endforeach; ?>
          <?php if(count($members)>6): ?><span class="chip" style="background:#e0f2fe;color:#075985;">+<?= count($members)-6 ?> more</span><?php endif; ?>
        </div>
        <?php else: ?><span style="color:#d1d5db;font-size:11.5px;">None assigned</span><?php endif; ?>
      </td>
      <td style="font-size:11.5px;color:#6b7280;white-space:nowrap;"><?= date('d M Y', strtotime($cat['created_at'])) ?></td>
      <td style="text-align:center;white-space:nowrap;">
        <a href="?edit=<?= $cat['id'] ?>" class="btn btn-warning btn-sm"><i class="fa-solid fa-pen-to-square"></i> Edit</a>
        <button type="button" class="btn btn-danger btn-sm" onclick="openDelete(<?= $cat['id'] ?>, '<?= addslashes(htmlspecialchars($cat['name'])) ?>', 'main')">
          <i class="fa-solid fa-trash"></i> Delete
        </button>
      </td>
    </tr>

    <!-- Sub-category expansion row -->
    <tr class="sub-expand-row" id="subRow<?= $cat['id'] ?>">
      <td class="sub-expand-cell" colspan="8">
        <div class="sub-inner">
          <div class="sub-header">
            <span class="sub-header-title"><i class="fa-solid fa-sitemap" style="margin-right:5px;"></i>Sub-categories of "<?= htmlspecialchars($cat['name']) ?>"</span>
            <button type="button" class="btn btn-purple btn-sm"
                    onclick="openSubModal(<?= $cat['id'] ?>, '<?= addslashes(htmlspecialchars($cat['name'])) ?>')">
              <i class="fa-solid fa-plus"></i> Add Sub-category
            </button>
          </div>

          <?php if($subs): ?>
          <table class="sub-table">
            <thead>
              <tr>
                <th>#</th><th>Sub-category Name</th><th>Customers</th><th>Members</th><th>Created</th><th style="text-align:center;">Actions</th>
              </tr>
            </thead>
            <tbody>
            <?php $si=1; foreach($subs as $sub):
              $smem_q = mysqli_query($conn, "
                  SELECT customer_code, customer_name
                  FROM ushop_letter_subcategory_customers
                  WHERE subcategory_id = {$sub['id']}
                  ORDER BY customer_name ASC
              ");
              $smembers = [];
              while($sm = mysqli_fetch_assoc($smem_q)) $smembers[] = $sm;

              /* Build JS array: ['code|||name', ...] for modal pre-selection */
              $smVals = implode(',', array_map(fn($x) => "'".addslashes($x['customer_code'].'|||'.$x['customer_name'])."'", $smembers));
            ?>
            <tr>
              <td style="color:#9ca3af;font-size:10.5px;"><?= $si++ ?></td>
              <td>
                <span style="font-weight:700;color:#7c3aed;">
                  <i class="fa-solid fa-angle-right" style="margin-right:4px;font-size:10px;color:#a78bfa;"></i>
                  <?= htmlspecialchars($sub['name']) ?>
                </span>
              </td>
              <td><span class="badge-count" style="font-size:11px;padding:2px 8px;"><i class="fa-solid fa-user" style="font-size:9px;"></i><?= $sub['scust_count'] ?></span></td>
              <td style="max-width:260px;">
                <?php if($smembers): ?>
                <div class="chip-list">
                  <?php foreach(array_slice($smembers,0,5) as $sm): ?>
                  <span class="chip" title="<?= htmlspecialchars($sm['customer_code']) ?>"><?= htmlspecialchars($sm['customer_name'] ?: $sm['customer_code']) ?></span>
                  <?php endforeach; ?>
                  <?php if(count($smembers)>5): ?><span class="chip" style="background:#ede9fe;color:#7c3aed;">+<?= count($smembers)-5 ?> more</span><?php endif; ?>
                </div>
                <?php else: ?><span style="color:#d1d5db;font-size:11px;">None</span><?php endif; ?>
              </td>
              <td style="font-size:11px;color:#9ca3af;white-space:nowrap;"><?= date('d M Y', strtotime($sub['created_at'])) ?></td>
              <td style="text-align:center;white-space:nowrap;">
                <button type="button" class="btn btn-warning btn-sm"
                        onclick="openSubEditModal(<?= $sub['id'] ?>, '<?= addslashes(htmlspecialchars($sub['name'])) ?>', <?= $cat['id'] ?>, [<?= $smVals ?>])">
                  <i class="fa-solid fa-pen-to-square"></i> Edit
                </button>
                <button type="button" class="btn btn-danger btn-sm"
                        onclick="openDelete(<?= $sub['id'] ?>, '<?= addslashes(htmlspecialchars($sub['name'])) ?>', 'sub')">
                  <i class="fa-solid fa-trash"></i> Delete
                </button>
              </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
          <?php else: ?>
          <div class="sub-empty"><i class="fa-solid fa-layer-group" style="display:block;font-size:18px;opacity:.3;margin-bottom:6px;"></i>No sub-categories yet. Click <strong>Add Sub-category</strong> to create one.</div>
          <?php endif; ?>
        </div>
      </td>
    </tr>
    <?php endwhile; ?>
    <?php if(mysqli_num_rows($cats)===0): ?>
    <tr><td colspan="8" style="text-align:center;padding:44px;color:#9ca3af;font-size:13px;">
      <i class="fa-solid fa-folder-open" style="font-size:24px;display:block;margin-bottom:8px;opacity:.4;"></i>No categories yet.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
  </div>
</div>

</div><!-- /page-wrap -->

<!-- ══ SUB-CATEGORY ADD/EDIT MODAL ══ -->
<div class="modal-overlay" id="subModal">
  <div class="modal-box">
    <div class="modal-head" style="border-left:4px solid #7c3aed;">
      <i class="fa-solid fa-sitemap" style="color:#7c3aed;"></i>
      <span class="modal-head-title" id="subModalTitle">Add Sub-category</span>
      <button type="button" onclick="closeSubModal()" style="background:none;border:none;cursor:pointer;color:#9ca3af;font-size:16px;"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <form method="POST" action="" id="subModalForm">
      <input type="hidden" name="action" id="subModalAction" value="sub_create">
      <input type="hidden" name="cat_id"  id="subModalCatId">
      <input type="hidden" name="sub_id"  id="subModalSubId">
      <div class="modal-body">
        <div class="form-row">
          <div class="form-group" style="min-width:100%;">
            <label><i class="fa-solid fa-tag"></i> Sub-category Name</label>
            <input type="text" name="sub_name" id="subModalName" placeholder="e.g. Platinum Tier, Zone A…" required>
          </div>
          <div class="form-group" style="min-width:100%;">
            <label><i class="fa-solid fa-users"></i> Customers</label>
            <label style="display:flex;align-items:center;gap:6px;margin-bottom:6px;cursor:pointer;user-select:none;">
              <input type="checkbox" id="noCustSub" onchange="toggleNoCust('subCustSelect','noCustSub')"
                     style="width:15px;height:15px;accent-color:#7c3aed;cursor:pointer;">
              <span style="font-size:12px;color:#6b7280;font-weight:600;text-transform:none;letter-spacing:0;">No customer selection</span>
            </label>
            <div id="subCustSelectWrap">
              <select name="sub_customer_ids[]" id="subCustSelect" multiple="multiple" class="sub-customer-select">
                <?php foreach($all_customers as $c): ?>
                <option value="<?= htmlspecialchars($c['customer_code'].'|||'.($c['customer_name']??'')) ?>">
                  <?= htmlspecialchars($c['customer_name']?:'Unknown') ?> — <?= htmlspecialchars($c['customer_code']) ?>
                </option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
        </div>
      </div>
      <div class="modal-foot">
        <button type="button" onclick="closeSubModal()" class="btn btn-secondary"><i class="fa-solid fa-xmark"></i> Cancel</button>
        <button type="submit" class="btn btn-purple" id="subModalSubmitBtn"><i class="fa-solid fa-plus"></i> Add Sub-category</button>
      </div>
    </form>
  </div>
</div>

<!-- ══ DELETE CONFIRM MODAL ══ -->
<div class="modal-overlay" id="deleteModal">
  <div class="del-modal-box">
    <h3><i class="fa-solid fa-triangle-exclamation" style="color:#dc2626;margin-right:8px;"></i>Confirm Delete</h3>
    <p>Are you sure you want to delete <strong id="delName"></strong>? All customer assignments will also be removed. This cannot be undone.</p>
    <form method="POST" action="">
      <input type="hidden" name="action" id="delAction" value="delete">
      <input type="hidden" name="cat_id"  id="delCatId">
      <input type="hidden" name="sub_id"  id="delSubId">
      <div class="modal-actions">
        <button type="button" class="btn btn-secondary" onclick="closeDelete()"><i class="fa-solid fa-xmark"></i> Cancel</button>
        <button type="submit" class="btn btn-danger"><i class="fa-solid fa-trash"></i> Yes, Delete</button>
      </div>
    </form>
  </div>
</div>

<script>
/* ── Select2 init ── */
$(document).ready(function(){
  $('.customer-select').select2({ placeholder:'Search and select customers…', allowClear:true, width:'100%' });
  $('.sub-customer-select').select2({ placeholder:'Search and select customers…', allowClear:true, width:'100%', dropdownParent:$('#subModal') });
  /* init no-cust toggles on page load */
  ['noCustCreate','noCustEdit'].forEach(function(id){
    var cb = document.getElementById(id);
    if(cb && cb.checked){ toggleNoCust(id==='noCustCreate'?'custSelect':'custSelectEdit', id); }
  });
});

/* ── No-customer toggle ── */
function toggleNoCust(selectId, checkboxId){
  var cb   = document.getElementById(checkboxId);
  var wrap = document.getElementById(selectId+'Wrap');
  var sel  = document.getElementById(selectId);
  if(!wrap) return;
  if(cb.checked){ wrap.style.display='none'; $(sel).val(null).trigger('change'); }
  else           { wrap.style.display=''; }
}

/* ── Expand sub-rows ── */
const openRows = {};
function toggleSub(id){
  var row = document.getElementById('subRow'+id);
  var btn = document.getElementById('chBtn'+id);
  if(openRows[id]){ row.classList.remove('open'); btn.classList.remove('open'); openRows[id]=false; }
  else            { row.classList.add('open');    btn.classList.add('open');    openRows[id]=true;  }
}

/* ── Sub-category ADD modal ── */
function openSubModal(catId, catName){
  document.getElementById('subModalAction').value  = 'sub_create';
  document.getElementById('subModalCatId').value   = catId;
  document.getElementById('subModalSubId').value   = '';
  document.getElementById('subModalName').value    = '';
  document.getElementById('subModalTitle').textContent = 'Add Sub-category to "'+catName+'"';
  document.getElementById('subModalSubmitBtn').innerHTML = '<i class="fa-solid fa-plus"></i> Add Sub-category';
  $('#subCustSelect').val(null).trigger('change');
  document.getElementById('noCustSub').checked = false;
  document.getElementById('subCustSelectWrap').style.display = '';
  document.getElementById('subModal').classList.add('open');
}

/* ── Sub-category EDIT modal ── */
/* selectedVals = ['code|||name', ...] */
function openSubEditModal(subId, subName, catId, selectedVals){
  document.getElementById('subModalAction').value  = 'sub_edit';
  document.getElementById('subModalCatId').value   = catId;
  document.getElementById('subModalSubId').value   = subId;
  document.getElementById('subModalName').value    = subName;
  document.getElementById('subModalTitle').textContent = 'Edit Sub-category';
  document.getElementById('subModalSubmitBtn').innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save Changes';
  $('#subCustSelect').val(selectedVals).trigger('change');
  var hasMembers = selectedVals.length > 0;
  document.getElementById('noCustSub').checked = !hasMembers;
  document.getElementById('subCustSelectWrap').style.display = hasMembers ? '' : 'none';
  document.getElementById('subModal').classList.add('open');
}

function closeSubModal(){
  document.getElementById('subModal').classList.remove('open');
}
document.getElementById('subModal').addEventListener('click', function(e){ if(e.target===this) closeSubModal(); });

/* ── Delete modal ── */
function openDelete(id, name, type){
  document.getElementById('delName').textContent = name;
  document.getElementById('delAction').value     = type==='sub' ? 'sub_delete' : 'delete';
  document.getElementById('delCatId').value      = type==='main' ? id : '';
  document.getElementById('delSubId').value      = type==='sub'  ? id : '';
  document.getElementById('deleteModal').classList.add('open');
}
function closeDelete(){
  document.getElementById('deleteModal').classList.remove('open');
}
document.getElementById('deleteModal').addEventListener('click', function(e){ if(e.target===this) closeDelete(); });
</script>

<?php include 'footer.php'; ?>