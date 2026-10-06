<?php
/**
 * admin/locations.php — Locations (Province / District / City data)
 * Lets the admin (re-)upload the Sri Lanka Province/District/City reference
 * data used by the Province → City dropdowns on the customer profile and
 * checkout pages. Accepts plain CSV exports (the same format Excel produces
 * via "Save As → CSV") — no external library needed to read them.
 */
require_once __DIR__ . '/includes/auth.php';

$pageTitle = 'Locations';
$active    = 'locations';

ensure_location_tables();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'upload_locations') {
        $tmpFiles = [];
        $rejected = [];
        foreach (['provinces' => 'provinces_csv', 'districts' => 'districts_csv', 'cities' => 'cities_csv'] as $key => $field) {
            if (!empty($_FILES[$field]['name']) && $_FILES[$field]['error'] === UPLOAD_ERR_OK) {
                $ext = strtolower(pathinfo($_FILES[$field]['name'], PATHINFO_EXTENSION));
                if ($ext !== 'csv') {
                    $rejected[] = $_FILES[$field]['name'] . ' (please save/export it as .csv first — e.g. Excel → File → Save As → CSV)';
                } else {
                    $tmpFiles[$key] = $_FILES[$field]['tmp_name'];
                }
            }
        }

        if ($rejected) {
            flash('err', 'Could not use: ' . implode(', ', $rejected));
        } elseif (!$tmpFiles) {
            flash('err', 'Choose at least one CSV file to upload.');
        } else {
            try {
                $counts = import_locations_csv($tmpFiles['provinces'] ?? null, $tmpFiles['districts'] ?? null, $tmpFiles['cities'] ?? null);
                $parts = [];
                if (isset($tmpFiles['provinces'])) { $parts[] = $counts['provinces'] . ' provinces'; }
                if (isset($tmpFiles['districts'])) { $parts[] = $counts['districts'] . ' districts'; }
                if (isset($tmpFiles['cities']))    { $parts[] = $counts['cities'] . ' cities'; }
                flash('ok', 'Imported ' . implode(', ', $parts) . ' successfully.');
            } catch (\Throwable $e) {
                error_log('Location import failed: ' . $e->getMessage());
                flash('err', 'Import failed — ' . $e->getMessage() . '. No changes were made to that table.');
            }
        }
        redirect('/admin/locations');
    }

    if ($action === 'reseed_defaults') {
        try {
            $seedDir = __DIR__ . '/../includes/seed';
            $counts = import_locations_csv($seedDir . '/provinces.csv', $seedDir . '/districts.csv', $seedDir . '/cities.csv');
            flash('ok', "Reset to the default dataset — {$counts['provinces']} provinces, {$counts['districts']} districts, {$counts['cities']} cities.");
        } catch (\Throwable $e) {
            error_log('Location reseed failed: ' . $e->getMessage());
            flash('err', 'Reset failed — ' . $e->getMessage());
        }
        redirect('/admin/locations');
    }
}

$counts = get_location_counts();
require __DIR__ . '/includes/header.php';
?>

<?php if ($m = flash('ok')): ?><div class="alert alert-success"><i class="fa-solid fa-circle-check"></i> <?= e($m) ?></div><?php endif; ?>
<?php if ($m = flash('err')): ?><div class="alert alert-error"><i class="fa-solid fa-triangle-exclamation"></i> <?= e($m) ?></div><?php endif; ?>

<div class="panel" style="margin-bottom:20px">
  <div class="panel-head"><h3><i class="fa-solid fa-map-location-dot"></i> Current Data</h3></div>
  <div class="panel-body">
    <div class="form-grid">
      <div><strong>Provinces:</strong> <?= (int)$counts['provinces'] ?></div>
      <div><strong>Districts:</strong> <?= (int)$counts['districts'] ?></div>
      <div><strong>Cities:</strong> <?= (int)$counts['cities'] ?></div>
    </div>
  </div>
</div>

<form method="post" enctype="multipart/form-data" autocomplete="off">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="upload_locations">

  <div class="panel" style="margin-bottom:20px">
    <div class="panel-head"><h3><i class="fa-solid fa-file-csv"></i> Upload / Update Location Data</h3></div>
    <div class="panel-body">
      <p class="hint" style="margin-bottom:16px">
        Upload CSV files (export from Excel as "CSV") to replace the Province, District and/or City lists used by
        the Province → City dropdowns at checkout and on customer profiles. Uploading any one file only replaces
        <em>that</em> table — leave the others blank to keep them as they are.
      </p>
      <div class="form-grid">
        <label>Provinces CSV <span class="hint" style="font-weight:400">(provinces_id, name_en, name_si, name_ta)</span>
          <input type="file" name="provinces_csv" accept=".csv">
        </label>
        <label>Districts CSV <span class="hint" style="font-weight:400">(district id, province_id, name_en, name_si, name_ta)</span>
          <input type="file" name="districts_csv" accept=".csv">
        </label>
        <label>Cities CSV <span class="hint" style="font-weight:400">(city id, district_id, name_en, name_si, name_ta, sub_name_en, sub_name_si, sub_name_ta, postcode, latitude, longitude)</span>
          <input type="file" name="cities_csv" accept=".csv">
        </label>
      </div>
      <p class="hint" style="margin-top:12px;color:var(--red-600, #c0181c)">
        <i class="fa-solid fa-triangle-exclamation"></i> Uploading a file <strong>replaces the whole table</strong> — export/back up
        your current list first if you've made manual edits you want to keep.
      </p>
    </div>
  </div>

  <button type="submit" class="btn-add" style="margin-bottom:16px"><i class="fa-solid fa-upload"></i> Upload &amp; Replace</button>
</form>

<form method="post" onsubmit="return confirm('Reset Provinces / Districts / Cities back to the default Sri Lanka dataset that shipped with the site? This replaces any changes you\'ve uploaded since.');" style="margin-bottom:28px">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="reseed_defaults">
  <button type="submit" class="mini-btn toggle"><i class="fa-solid fa-rotate-left"></i> Reset to Default Dataset</button>
</form>

<?php require __DIR__ . '/includes/footer.php'; ?>
