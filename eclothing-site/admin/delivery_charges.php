<?php
/**
 * admin/delivery_charges.php — Delivery Charges
 * A default flat delivery charge, optionally overridden per city.
 * Cities come from the same Province/District/City data used at checkout
 * (Admin → Locations). Download a CSV template (every city + its current
 * charge), edit it in Excel, and re-upload to bulk-set per-city rates.
 */
require_once __DIR__ . '/includes/auth.php';

$pageTitle = 'Delivery Charges';
$active    = 'delivery_charges';

ensure_location_tables();
ensure_delivery_charges_table();

/* ---------- CSV template download (GET, so it can be a plain link) ---------- */
if (($_GET['action'] ?? '') === 'download_template') {
    $rows = get_delivery_charges_for_export();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="delivery_charges_template.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['city_id', 'city_name', 'district', 'province', 'delivery_charge']);
    foreach ($rows as $r) {
        fputcsv($out, [$r['city_id'], $r['city_name'], $r['district_name'], $r['province_name'], number_format((float)$r['delivery_charge'], 2, '.', '')]);
    }
    fclose($out);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_default') {
        $charge = (float)($_POST['default_delivery_charge'] ?? 0);
        if ($charge < 0) {
            flash('err', 'Delivery charge cannot be negative.');
        } else {
            db()->prepare('UPDATE site_settings SET default_delivery_charge = ? WHERE id = 1')->execute([$charge]);
            flash('ok', 'Default delivery charge saved.');
        }
        redirect('/admin/delivery_charges');
    }

    if ($action === 'upload_charges') {
        if (empty($_FILES['charges_csv']['name']) || $_FILES['charges_csv']['error'] !== UPLOAD_ERR_OK) {
            flash('err', 'Choose a CSV file to upload.');
        } elseif (strtolower(pathinfo($_FILES['charges_csv']['name'], PATHINFO_EXTENSION)) !== 'csv') {
            flash('err', 'Please upload a .csv file (export it from Excel as "CSV" first).');
        } else {
            try {
                $count = import_delivery_charges_csv($_FILES['charges_csv']['tmp_name']);
                flash('ok', "Imported delivery charges for $count cities.");
            } catch (\Throwable $e) {
                error_log('Delivery charge import failed: ' . $e->getMessage());
                flash('err', 'Import failed — ' . $e->getMessage());
            }
        }
        redirect('/admin/delivery_charges');
    }

    if ($action === 'clear_city_charge') {
        $cityId = (int)($_POST['city_id'] ?? 0);
        db()->prepare('DELETE FROM delivery_charges WHERE city_id = ?')->execute([$cityId]);
        flash('ok', 'That city now uses the default delivery charge again.');
        redirect('/admin/delivery_charges');
    }
}

$siteSettings = get_site_settings();
$customCount  = (int)db()->query('SELECT COUNT(*) FROM delivery_charges')->fetchColumn();
$customRows   = db()->query("SELECT dc.city_id, dc.delivery_charge, c.name_en AS city_name, p.name_en AS province_name
                              FROM delivery_charges dc
                              INNER JOIN cities c ON c.id = dc.city_id
                              INNER JOIN districts d ON d.id = c.district_id
                              INNER JOIN provinces p ON p.id = d.province_id
                              ORDER BY p.name_en, c.name_en
                              LIMIT 200")->fetchAll();

require __DIR__ . '/includes/header.php';
?>

<?php if ($m = flash('ok')): ?><div class="alert alert-success">✔ <?= e($m) ?></div><?php endif; ?>
<?php if ($m = flash('err')): ?><div class="alert alert-error">⚠ <?= e($m) ?></div><?php endif; ?>

<div class="panel" style="margin-bottom:20px">
  <div class="panel-head"><h3><i class="fa-solid fa-truck"></i> Default Delivery Charge</h3></div>
  <div class="panel-body">
    <p class="hint" style="margin-bottom:14px">Applied at checkout for any city that doesn't have its own rate set below. Enter <strong>0</strong> for free delivery by default.</p>
    <form method="post" class="form-grid" style="align-items:end">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_default">
      <label>Default Charge (Rs.)
        <input type="number" name="default_delivery_charge" step="0.01" min="0" value="<?= e((string)$siteSettings['default_delivery_charge']) ?>">
      </label>
      <button type="submit" class="btn-add">Save</button>
    </form>
  </div>
</div>

<div class="panel" style="margin-bottom:20px">
  <div class="panel-head"><h3><i class="fa-solid fa-map-location-dot"></i> Per-City Delivery Charges</h3></div>
  <div class="panel-body">
    <p class="hint" style="margin-bottom:14px">
      <?= $customCount ?> of the cities in Admin → Locations currently have their own delivery charge; every other city uses the default above.
      Download the template below (every city + its current charge), edit the <code>delivery_charge</code> column in Excel, and re-upload —
      only the <code>city_id</code> and <code>delivery_charge</code> columns are read, so extra columns like the city name are just for your reference.
    </p>

    <a href="<?= BASE_URL ?>/admin/delivery_charges?action=download_template" class="mini-btn toggle" style="display:inline-flex;margin-bottom:18px">
      <i class="fa-solid fa-download"></i> Download City List (CSV)
    </a>

    <form method="post" enctype="multipart/form-data" class="form-grid" style="align-items:end">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="upload_charges">
      <label>Upload Updated CSV
        <input type="file" name="charges_csv" accept=".csv" required>
      </label>
      <button type="submit" class="btn-add"><i class="fa-solid fa-upload"></i> Import</button>
    </form>
  </div>
</div>

<?php if ($customRows): ?>
<div class="panel" style="margin-bottom:28px">
  <div class="panel-head"><h3>Cities With a Custom Charge (<?= $customCount ?><?= $customCount > 200 ? ', showing first 200' : '' ?>)</h3></div>
  <div class="panel-body" style="padding:0">
    <table>
      <thead><tr><th>City</th><th>Province</th><th>Delivery Charge</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($customRows as $r): ?>
        <tr>
          <td><b><?= e($r['city_name']) ?></b></td>
          <td><?= e($r['province_name']) ?></td>
          <td><?= (float)$r['delivery_charge'] === 0.0 ? '<span class="verified-badge">FREE</span>' : 'Rs. ' . number_format((float)$r['delivery_charge'], 2) ?></td>
          <td>
            <form method="post" onsubmit="return confirm('Remove the custom charge for <?= e($r['city_name']) ?>? It will go back to using the default.');">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="clear_city_charge">
              <input type="hidden" name="city_id" value="<?= (int)$r['city_id'] ?>">
              <button class="mini-btn del" type="submit">Reset to Default</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
