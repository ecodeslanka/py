<?php
/**
 * admin/items_import.php — Bulk Item Import + Import-based Delete
 *
 *  1. IMPORT   — upload a CSV (Excel → "Save As → CSV UTF-8") of items.
 *                Simple items only: no variations, no images.
 *                Existing SKUs can be updated or skipped. Missing categories /
 *                brands can be auto-created.
 *  2. HISTORY  — every import is logged. "Delete imported items" removes
 *                exactly the items that import CREATED (one-click undo).
 *  3. DELETE BY CSV — upload a list of SKUs and those items are deleted.
 *  4. TEMPLATE / EXPORT — download a blank template or all items as CSV.
 *
 * Self-contained: creates/migrates its own tables on first load.
 */
require_once __DIR__ . '/includes/auth.php';

$pageTitle = 'Import Items';
$active    = 'items_import';

@set_time_limit(300);

/* ============================================================
   AUTO-MIGRATE
   ============================================================ */
ensure_categories_table();
ensure_items_table();

function imp_has_column(string $table, string $column): bool
{
    $st = db()->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $st->execute([$table, $column]);
    return (int)$st->fetchColumn() > 0;
}
if (!imp_has_column('items', 'category_id')) {
    db()->exec('ALTER TABLE items ADD COLUMN category_id INT UNSIGNED DEFAULT NULL AFTER name');
}
if (!imp_has_column('items', 'brand_id')) {
    db()->exec('ALTER TABLE items ADD COLUMN brand_id INT UNSIGNED DEFAULT NULL AFTER category_id');
}
if (!imp_has_column('items', 'koko_price')) {
    db()->exec('ALTER TABLE items ADD COLUMN koko_price DECIMAL(12,2) DEFAULT NULL AFTER special_price');
}
if (!imp_has_column('items', 'import_batch_id')) {
    db()->exec('ALTER TABLE items ADD COLUMN import_batch_id INT UNSIGNED DEFAULT NULL, ADD INDEX idx_items_import_batch (import_batch_id)');
}
db()->exec("
CREATE TABLE IF NOT EXISTS item_import_batches (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  file_name     VARCHAR(255) NOT NULL,
  total_rows    INT NOT NULL DEFAULT 0,
  created_count INT NOT NULL DEFAULT 0,
  updated_count INT NOT NULL DEFAULT 0,
  skipped_count INT NOT NULL DEFAULT 0,
  error_count   INT NOT NULL DEFAULT 0,
  admin_name    VARCHAR(120) DEFAULT NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

/* ============================================================
   Column definitions (header → field). Headers are matched
   case-insensitively; spaces / dashes are treated as "_".
   ============================================================ */
const IMP_COLUMNS = [
    'sku'           => ['sku', 'sku_code', 'code', 'item_code', 'product_code'],
    'name'          => ['name', 'item_name', 'product_name', 'title'],
    'category'      => ['category', 'category_name', 'category_path'],
    'category_id'   => ['category_id'],
    'brand'         => ['brand', 'brand_name', 'vehicle'],
    'description'   => ['description', 'desc', 'details'],
    'condition'     => ['condition', 'condition_type'],
    'warranty'      => ['warranty'],
    'stock_status'  => ['stock_status', 'status', 'availability'],
    'stock_qty'     => ['stock_qty', 'qty', 'quantity', 'stock'],
    'cost_price'    => ['cost_price', 'cost'],
    'selling_price' => ['selling_price', 'price', 'sell_price', 'sale_price'],
    'special_price' => ['special_price', 'special', 'offer_price', 'discount_price'],
    'koko_price'    => ['koko_price', 'koko'],
    'flash_sale'    => ['flash_sale', 'is_flash_sale', 'flash'],
    'top_item'      => ['top_item', 'is_top_item', 'top_selling', 'top'],
];
const IMP_TEMPLATE_HEADERS = ['sku', 'name', 'category', 'brand', 'description', 'condition', 'warranty', 'stock_status', 'stock_qty', 'cost_price', 'selling_price', 'special_price', 'koko_price', 'flash_sale', 'top_item'];
const IMP_MAX_ROWS = 5000;

/* ============================================================
   CSV helpers
   ============================================================ */
function imp_norm_header(string $h): string
{
    $h = preg_replace('/^\xEF\xBB\xBF/', '', $h); // UTF-8 BOM
    $h = strtolower(trim($h));
    $h = preg_replace('/[^a-z0-9]+/', '_', $h);
    return trim($h, '_');
}

function imp_to_utf8(string $s): string
{
    if ($s === '' || mb_check_encoding($s, 'UTF-8')) return $s;
    return mb_convert_encoding($s, 'UTF-8', 'Windows-1252');
}

/** Reads an uploaded CSV into [normalisedHeaders[], rows[][], null, rawHeaderRow[]]; returns [null, null, error] on failure. */
function imp_read_csv(array $file): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return [null, null, 'Please choose a CSV file to upload.'];
    if ($file['error'] !== UPLOAD_ERR_OK) return [null, null, 'The upload failed — please try again.'];
    if ($file['size'] > 10 * 1024 * 1024) return [null, null, 'The file is too large (max 10MB).'];
    $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['csv', 'txt'], true)) {
        return [null, null, 'Please upload a .csv file. In Excel use File → Save As → "CSV UTF-8 (Comma delimited)".'];
    }
    $fh = fopen($file['tmp_name'], 'r');
    if (!$fh) return [null, null, 'Could not read the uploaded file.'];

    // detect delimiter from the first line
    $first = (string)fgets($fh);
    rewind($fh);
    $delim = ',';
    $best = 0;
    foreach ([',', ';', "\t", '|'] as $d) {
        $c = substr_count($first, $d);
        if ($c > $best) { $best = $c; $delim = $d; }
    }

    $headers = null;
    $rawHeader = [];
    $rows = [];
    while (($r = fgetcsv($fh, 0, $delim, '"', '\\')) !== false) {
        if ($r === [null] || (count($r) === 1 && trim((string)$r[0]) === '')) continue; // blank line
        $r = array_map(fn($v) => imp_to_utf8((string)$v), $r);
        if ($headers === null) { $rawHeader = array_map(fn($v) => trim(preg_replace('/^\xEF\xBB\xBF/', '', $v)), $r); $headers = array_map('imp_norm_header', $r); continue; }
        $rows[] = $r;
        if (count($rows) > IMP_MAX_ROWS) break;
    }
    fclose($fh);
    if (!$headers) return [null, null, 'The file is empty.'];
    return [$headers, $rows, null, $rawHeader];
}

/** Maps normalised headers to our fields → [field => column index]. */
function imp_map_headers(array $headers): array
{
    $map = [];
    foreach (IMP_COLUMNS as $field => $aliases) {
        foreach ($headers as $i => $h) {
            if (in_array($h, $aliases, true)) { $map[$field] = $i; break; }
        }
    }
    return $map;
}

function imp_money(string $v): ?float
{
    $v = trim(str_ireplace(['rs.', 'rs', 'lkr', ','], '', $v));
    if ($v === '') return null;
    return is_numeric($v) ? round((float)$v, 2) : NAN;
}

function imp_bool(string $v): int
{
    return in_array(strtolower(trim($v)), ['1', 'yes', 'y', 'true', 'on', 'x'], true) ? 1 : 0;
}

function imp_stock_status(string $v): ?string
{
    $v = preg_replace('/[^a-z]/', '', strtolower($v));
    if ($v === '') return null;
    if (in_array($v, ['instock', 'in', 'available', 'yes'], true)) return 'in_stock';
    if (in_array($v, ['outofstock', 'out', 'soldout', 'no', 'unavailable'], true)) return 'out_of_stock';
    if (in_array($v, ['preorder', 'pre', 'backorder'], true)) return 'pre_order';
    return 'invalid';
}

function imp_condition(string $v): ?string
{
    $v = strtolower(trim($v));
    if ($v === '') return null;
    if (in_array($v, ['new', 'brand new', 'brandnew'], true)) return 'new';
    if (in_array($v, ['old', 'used', 'second hand', 'secondhand', 'reconditioned', 'recondition'], true)) return 'old';
    return 'invalid';
}

/* ---- category / brand resolvers (cached; auto-create when allowed) ---- */
function imp_find_or_create_category(string $raw, bool $create, array &$cache, int &$createdCount): ?int
{
    $raw = trim($raw);
    if ($raw === '') return null;
    if (ctype_digit($raw)) {
        $c = get_category((int)$raw);
        return $c ? (int)$c['id'] : null;
    }
    $parts = array_values(array_filter(array_map('trim', preg_split('/\s*(?:>|\/|»)\s*/u', $raw)), fn($p) => $p !== ''));
    if (!$parts) return null;

    // single name → match any category with that name (top-most level first)
    if (count($parts) === 1) {
        $key = 'n:' . mb_strtolower($parts[0]);
        if (array_key_exists($key, $cache)) return $cache[$key];
        $st = db()->prepare('SELECT id FROM categories WHERE LOWER(name) = LOWER(?) ORDER BY level ASC, id ASC LIMIT 1');
        $st->execute([$parts[0]]);
        $id = $st->fetchColumn();
        if ($id) return $cache[$key] = (int)$id;
    }

    // path "Parent > Child > Sub-child" (max 3 levels)
    $parts = array_slice($parts, 0, 3);
    $parentId = null;
    foreach ($parts as $lvlIdx => $name) {
        $name = mb_substr($name, 0, 100);
        $key = 'p:' . ($parentId ?? 0) . ':' . mb_strtolower($name);
        if (array_key_exists($key, $cache) && $cache[$key]) { $parentId = $cache[$key]; continue; }
        if ($parentId === null) {
            $st = db()->prepare('SELECT id FROM categories WHERE parent_id IS NULL AND LOWER(name) = LOWER(?) LIMIT 1');
            $st->execute([$name]);
        } else {
            $st = db()->prepare('SELECT id FROM categories WHERE parent_id = ? AND LOWER(name) = LOWER(?) LIMIT 1');
            $st->execute([$parentId, $name]);
        }
        $id = $st->fetchColumn();
        if (!$id) {
            if (!$create) return $cache[$key] = null;
            db()->prepare('INSERT INTO categories (parent_id, name, slug, level, sort_order) VALUES (?, ?, ?, ?, 0)')
                ->execute([$parentId, $name, make_unique_slug($name), $lvlIdx + 1]);
            $id = (int)db()->lastInsertId();
            $createdCount++;
        }
        $parentId = $cache[$key] = (int)$id;
    }
    if (count($parts) === 1) { $cache['n:' . mb_strtolower($parts[0])] = $parentId; }
    return $parentId;
}

function imp_find_or_create_brand(string $raw, bool $create, array &$cache, int &$createdCount): ?int
{
    $raw = mb_substr(trim($raw), 0, 120);
    if ($raw === '') return null;
    $key = mb_strtolower($raw);
    if (array_key_exists($key, $cache)) return $cache[$key];
    if (ctype_digit($raw)) {
        $st = db()->prepare('SELECT id FROM brands WHERE id = ?');
        $st->execute([(int)$raw]);
        if ($id = $st->fetchColumn()) return $cache[$key] = (int)$id;
    }
    $st = db()->prepare('SELECT id FROM brands WHERE LOWER(name) = LOWER(?) LIMIT 1');
    $st->execute([$raw]);
    $id = $st->fetchColumn();
    if (!$id && $create) {
        db()->prepare('INSERT INTO brands (name) VALUES (?)')->execute([$raw]);
        $id = (int)db()->lastInsertId();
        $createdCount++;
    }
    return $cache[$key] = $id ? (int)$id : null;
}

function imp_send_csv(string $filename, array $headers, iterable $rows): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM so Excel opens UTF-8 correctly
    fputcsv($out, $headers, ',', '"', '\\');
    foreach ($rows as $r) { fputcsv($out, $r, ',', '"', '\\'); }
    fclose($out);
    exit;
}

/* ============================================================
   GET downloads: template / export
   ============================================================ */
if (($_GET['download'] ?? '') === 'template') {
    imp_send_csv('items_import_template.csv', IMP_TEMPLATE_HEADERS, [
        ['EXAMPLE-001', 'Classic Crew Neck T-Shirt', 'T-Shirts', 'ECLOTHING Basics', '100% cotton, regular fit', 'new', '', 'in_stock', '25', '2500', '3500', '3200', '', 'no', 'yes'],
        ['EXAMPLE-002', 'Sample Headlight Assembly', 'Lights > Headlights', 'Toyota', '', 'used', '', 'pre_order', '0', '8000', '12000', '', '12500', 'no', 'no'],
    ]);
}
if (($_GET['download'] ?? '') === 'export') {
    $rows = db()->query('SELECT i.*, c.name AS category_name, b.name AS brand_name
                         FROM items i
                         LEFT JOIN categories c ON c.id = i.category_id
                         LEFT JOIN brands b ON b.id = i.brand_id
                         ORDER BY i.id ASC')->fetchAll();
    $gen = (function () use ($rows) {
        foreach ($rows as $r) {
            yield [
                $r['sku'], $r['name'], $r['category_name'] ?? '', $r['brand_name'] ?? '', $r['description'] ?? '',
                $r['condition_type'], $r['warranty'] ?? '', $r['stock_status'], $r['stock_qty'],
                $r['cost_price'], $r['selling_price'], $r['special_price'] ?? '', $r['koko_price'] ?? '',
                !empty($r['is_flash_sale']) ? 'yes' : 'no', !empty($r['is_top_item']) ? 'yes' : 'no',
            ];
        }
    })();
    imp_send_csv('items_export_' . date('Y-m-d_His') . '.csv', IMP_TEMPLATE_HEADERS, $gen);
}

/* ============================================================
   POST actions
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    /* ---------------- IMPORT ---------------- */
    if ($action === 'import') {
        $mode       = ($_POST['existing_mode'] ?? 'update') === 'skip' ? 'skip' : 'update';
        $createCats = isset($_POST['create_categories']);
        $createBrs  = isset($_POST['create_brands']);

        [$headers, $rows, $err] = imp_read_csv($_FILES['csv_file'] ?? []);
        if ($err) { flash('err', $err); redirect('/admin/items_import'); }

        $map = imp_map_headers($headers);
        if (!isset($map['sku'])) {
            flash('err', 'The file needs a "sku" column in the first row. Download the template to see the expected columns.');
            redirect('/admin/items_import');
        }
        if (!$rows) { flash('err', 'The file has a header row but no item rows.'); redirect('/admin/items_import'); }
        $tooMany = count($rows) > IMP_MAX_ROWS;
        if ($tooMany) { $rows = array_slice($rows, 0, IMP_MAX_ROWS); }

        $cell = function (array $r, string $field) use ($map): ?string {
            if (!isset($map[$field])) return null;          // column not in file
            return trim((string)($r[$map[$field]] ?? ''));  // '' = column present but blank
        };

        $pdo = db();
        $pdo->prepare('INSERT INTO item_import_batches (file_name, admin_name) VALUES (?, ?)')
            ->execute([mb_substr(basename((string)$_FILES['csv_file']['name']), 0, 255), $adminName]);
        $batchId = (int)$pdo->lastInsertId();

        $created = $updated = $skipped = 0;
        $newCats = $newBrands = 0;
        $errors = [];
        $catCache = $brandCache = [];
        $seenSkus = [];

        $findSku = $pdo->prepare('SELECT id FROM items WHERE sku = ?');

        $pdo->beginTransaction();
        try {
            foreach ($rows as $idx => $r) {
                $line = $idx + 2; // +1 header, +1 human numbering
                $sku  = (string)$cell($r, 'sku');
                if ($sku === '') {
                    // silently ignore fully-empty lines, report others
                    if (implode('', array_map('trim', $r)) !== '') { $errors[] = "Row $line: SKU is empty — skipped."; }
                    continue;
                }
                if (mb_strlen($sku) > 60) { $errors[] = "Row $line: SKU \"$sku\" is longer than 60 characters."; continue; }
                $skuKey = mb_strtolower($sku);
                if (isset($seenSkus[$skuKey])) { $errors[] = "Row $line: SKU \"$sku\" appears more than once in the file (first on row {$seenSkus[$skuKey]}) — skipped."; continue; }
                $seenSkus[$skuKey] = $line;

                $findSku->execute([$sku]);
                $existingId = (int)($findSku->fetchColumn() ?: 0);

                if ($existingId && $mode === 'skip') { $skipped++; continue; }

                /* ---- gather + validate values ---- */
                $vals = [];   // column => value (only columns we will write)
                $rowErr = [];

                $name = $cell($r, 'name');
                if ($name !== null && $name !== '') {
                    if (mb_strlen($name) > 300) { $rowErr[] = 'name is longer than 300 characters'; }
                    $vals['name'] = $name;
                } elseif (!$existingId) {
                    $rowErr[] = 'name is required for new items';
                }

                foreach (['cost_price', 'selling_price', 'special_price', 'koko_price'] as $pf) {
                    $raw = $cell($r, $pf);
                    if ($raw === null || $raw === '') continue;
                    $num = imp_money($raw);
                    if (is_float($num) && is_nan($num)) { $rowErr[] = "$pf \"$raw\" is not a number"; continue; }
                    if ($num < 0) { $rowErr[] = "$pf cannot be negative"; continue; }
                    $vals[$pf] = $num;
                }
                if (!$existingId && !isset($vals['selling_price'])) { $rowErr[] = 'selling_price is required for new items'; }

                $cond = $cell($r, 'condition');
                if ($cond !== null && $cond !== '') {
                    $c = imp_condition($cond);
                    if ($c === 'invalid') { $rowErr[] = "condition \"$cond\" must be new or used"; } else { $vals['condition_type'] = $c; }
                }

                $ss = $cell($r, 'stock_status');
                if ($ss !== null && $ss !== '') {
                    $s = imp_stock_status($ss);
                    if ($s === 'invalid') { $rowErr[] = "stock_status \"$ss\" must be in_stock, out_of_stock or pre_order"; } else { $vals['stock_status'] = $s; }
                }

                $qty = $cell($r, 'stock_qty');
                if ($qty !== null && $qty !== '') {
                    if (!is_numeric(str_replace(',', '', $qty))) { $rowErr[] = "stock_qty \"$qty\" is not a number"; }
                    else { $vals['stock_qty'] = max(0, (int)str_replace(',', '', $qty)); }
                }

                $war = $cell($r, 'warranty');
                if ($war !== null && $war !== '') { $vals['warranty'] = mb_substr($war, 0, 150); }

                $desc = $cell($r, 'description');
                if ($desc !== null && $desc !== '') {
                    // keep simple HTML as-is; turn plain text into safe HTML with line breaks
                    $vals['description'] = strip_tags($desc) !== $desc ? $desc : nl2br(e($desc), false);
                }

                $fs = $cell($r, 'flash_sale');
                if ($fs !== null && $fs !== '') { $vals['is_flash_sale'] = imp_bool($fs); }
                $tp = $cell($r, 'top_item');
                if ($tp !== null && $tp !== '') { $vals['is_top_item'] = imp_bool($tp); }

                if ($rowErr) { $errors[] = "Row $line (SKU $sku): " . implode('; ', $rowErr) . ' — skipped.'; continue; }

                /* special price must not exceed selling price (compare with DB value when updating) */
                $sellCheck = $vals['selling_price'] ?? null;
                if ($existingId && $sellCheck === null && isset($vals['special_price'])) {
                    $q = $pdo->prepare('SELECT selling_price FROM items WHERE id = ?');
                    $q->execute([$existingId]);
                    $sellCheck = (float)$q->fetchColumn();
                }
                if (isset($vals['special_price']) && $sellCheck !== null && $vals['special_price'] > $sellCheck) {
                    $errors[] = "Row $line (SKU $sku): special_price is higher than selling_price — skipped.";
                    continue;
                }

                /* category + brand (lookups only after the row is otherwise valid) */
                $catRaw = $cell($r, 'category_id');
                if ($catRaw === null || $catRaw === '') { $catRaw = $cell($r, 'category'); }
                if ($catRaw !== null && $catRaw !== '') {
                    $cid = imp_find_or_create_category($catRaw, $createCats, $catCache, $newCats);
                    if ($cid) { $vals['category_id'] = $cid; }
                    else { $errors[] = "Row $line (SKU $sku): category \"$catRaw\" not found — item saved without a category."; }
                }
                $brRaw = $cell($r, 'brand');
                if ($brRaw !== null && $brRaw !== '') {
                    $bid = imp_find_or_create_brand($brRaw, $createBrs, $brandCache, $newBrands);
                    if ($bid) { $vals['brand_id'] = $bid; }
                    else { $errors[] = "Row $line (SKU $sku): brand \"$brRaw\" not found — item saved without a brand."; }
                }

                /* ---- write ---- */
                if ($existingId) {
                    if (!$vals) { $skipped++; continue; }
                    $sets = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($vals)));
                    $pdo->prepare("UPDATE items SET $sets WHERE id = ?")->execute(array_merge(array_values($vals), [$existingId]));
                    $updated++;
                } else {
                    $vals['sku'] = $sku;
                    $vals += [
                        'condition_type' => 'new',
                        'stock_qty'      => 0,
                        'cost_price'     => 0,
                        'has_variations' => 0,
                    ];
                    if (!isset($vals['stock_status'])) {
                        $vals['stock_status'] = 'in_stock';
                    }
                    $vals['import_batch_id'] = $batchId;
                    $cols = array_keys($vals);
                    $pdo->prepare('INSERT INTO items (`' . implode('`,`', $cols) . '`) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')
                        ->execute(array_values($vals));
                    $created++;
                }
            }

            $pdo->prepare('UPDATE item_import_batches SET total_rows=?, created_count=?, updated_count=?, skipped_count=?, error_count=? WHERE id=?')
                ->execute([count($rows), $created, $updated, $skipped, count($errors), $batchId]);
            $pdo->commit();
        } catch (Throwable $ex) {
            $pdo->rollBack();
            $pdo->prepare('DELETE FROM item_import_batches WHERE id = ?')->execute([$batchId]);
            error_log('Item import failed: ' . $ex->getMessage());
            flash('err', 'The import failed and nothing was saved: ' . $ex->getMessage());
            redirect('/admin/items_import');
        }

        $msg = "Import finished: $created created, $updated updated, $skipped skipped";
        if ($newCats)   { $msg .= ", $newCats new categor" . ($newCats === 1 ? 'y' : 'ies'); }
        if ($newBrands) { $msg .= ", $newBrands new brand" . ($newBrands === 1 ? '' : 's'); }
        $msg .= '.';
        if ($tooMany) { $msg .= ' Only the first ' . IMP_MAX_ROWS . ' rows were processed — split larger files.'; }
        flash('ok', $msg);
        $_SESSION['import_report'] = array_slice($errors, 0, 200);
        $_SESSION['import_report_more'] = max(0, count($errors) - 200);
        redirect('/admin/items_import');
    }

    /* ---------------- UNDO an import: delete the items it created ---------------- */
    if ($action === 'delete_batch_items') {
        $bid = (int)($_POST['batch_id'] ?? 0);
        $st = db()->prepare('SELECT id FROM items WHERE import_batch_id = ?');
        $st->execute([$bid]);
        $n = delete_items_by_ids($st->fetchAll(PDO::FETCH_COLUMN));
        db()->prepare('DELETE FROM item_import_batches WHERE id = ?')->execute([$bid]);
        flash('ok', "Import #$bid undone — $n item" . ($n === 1 ? '' : 's') . ' deleted. (Items it only updated were left as they are.)');
        redirect('/admin/items_import');
    }

    /* ---------------- remove a log entry only (keeps items) ---------------- */
    if ($action === 'forget_batch') {
        $bid = (int)($_POST['batch_id'] ?? 0);
        db()->prepare('UPDATE items SET import_batch_id = NULL WHERE import_batch_id = ?')->execute([$bid]);
        db()->prepare('DELETE FROM item_import_batches WHERE id = ?')->execute([$bid]);
        flash('ok', "Import #$bid removed from history. Its items were kept.");
        redirect('/admin/items_import');
    }

    /* ---------------- DELETE items listed in a CSV (by SKU) ---------------- */
    if ($action === 'delete_by_csv') {
        [$headers, $rows, $err, $rawHeader] = imp_read_csv($_FILES['delete_file'] ?? []) + [3 => []];
        if ($err) { flash('err', $err); redirect('/admin/items_import'); }
        $map = imp_map_headers($headers);
        $skuIdx = $map['sku'] ?? null;
        if ($skuIdx === null) {
            // no recognised header → treat the first column as SKUs, including the first line
            $skuIdx = 0;
            array_unshift($rows, $rawHeader);
        }
        $skus = [];
        foreach ($rows as $r) {
            $s = trim((string)($r[$skuIdx] ?? ''));
            if ($s !== '') $skus[mb_strtolower($s)] = $s;
        }
        if (!$skus) { flash('err', 'No SKUs found in the file.'); redirect('/admin/items_import'); }

        $ids = [];
        foreach (array_chunk(array_values($skus), 500) as $chunk) {
            $inQ = implode(',', array_fill(0, count($chunk), '?'));
            $st = db()->prepare("SELECT id FROM items WHERE sku IN ($inQ)");
            $st->execute($chunk);
            $ids = array_merge($ids, $st->fetchAll(PDO::FETCH_COLUMN));
        }
        $n = delete_items_by_ids($ids);
        $notFound = count($skus) - $n;
        flash('ok', "$n item" . ($n === 1 ? '' : 's') . ' deleted' . ($notFound > 0 ? " ($notFound SKU" . ($notFound === 1 ? ' was' : 's were') . ' not found)' : '') . '.');
        redirect('/admin/items_import');
    }
}

/* ============================================================
   Page data
   ============================================================ */
$batches = db()->query('SELECT b.*, (SELECT COUNT(*) FROM items i WHERE i.import_batch_id = b.id) AS live_items
                        FROM item_import_batches b ORDER BY b.id DESC LIMIT 50')->fetchAll();
$totalItems = (int)db()->query('SELECT COUNT(*) FROM items')->fetchColumn();
$report     = $_SESSION['import_report'] ?? [];
$reportMore = (int)($_SESSION['import_report_more'] ?? 0);
unset($_SESSION['import_report'], $_SESSION['import_report_more']);

require __DIR__ . '/includes/header.php';
?>

<?php if ($m = flash('ok')): ?><div class="alert alert-success">✔ <?= e($m) ?></div><?php endif; ?>
<?php if ($m = flash('err')): ?><div class="alert alert-error">⚠ <?= e($m) ?></div><?php endif; ?>

<?php if ($report): ?>
<div class="panel">
  <div class="panel-head"><h3>⚠ Rows that need attention (<?= count($report) + $reportMore ?>)</h3></div>
  <div class="panel-body" style="max-height:300px;overflow:auto;font-size:13px;line-height:1.7">
    <?php foreach ($report as $line): ?><div><?= e($line) ?></div><?php endforeach; ?>
    <?php if ($reportMore): ?><div style="color:var(--muted)">…and <?= $reportMore ?> more.</div><?php endif; ?>
  </div>
</div>
<?php endif; ?>

<div class="panel-head" style="padding:0 2px 14px">
  <h3 style="font-size:20px">Import Items</h3>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <a href="<?= BASE_URL ?>/admin/items_import?download=template" class="mini-btn edit"><i class="fa-solid fa-download"></i> Download Template</a>
    <?php if ($totalItems): ?>
    <a href="<?= BASE_URL ?>/admin/items_import?download=export" class="mini-btn add-val"><i class="fa-solid fa-file-csv"></i> Export All Items (<?= $totalItems ?>)</a>
    <?php endif; ?>
    <a href="<?= BASE_URL ?>/admin/items" class="mini-btn toggle">← Back to Items</a>
  </div>
</div>

<!-- ===== 1. IMPORT ===== -->
<div class="panel">
  <div class="panel-head"><h3><i class="fa-solid fa-file-import"></i> 1. Upload Items (CSV)</h3></div>
  <div class="panel-body">
    <form method="post" enctype="multipart/form-data" onsubmit="this.querySelector('button[type=submit]').disabled=true;this.querySelector('button[type=submit]').textContent='Importing… please wait';">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="import">

      <div class="dropzone" id="csvDrop" onclick="document.getElementById('csvFile').click()" style="cursor:pointer">
        <p id="csvDropText">📄 Drag &amp; drop your CSV here, or click to choose a file</p>
      </div>
      <input type="file" name="csv_file" id="csvFile" accept=".csv,.txt,text/csv" required style="display:none" onchange="showCsvName(this,'csvDropText')">

      <div class="form-grid" style="margin-top:16px">
        <label>If an SKU already exists
          <select name="existing_mode">
            <option value="update">Update the existing item</option>
            <option value="skip">Skip it (keep existing item)</option>
          </select>
        </label>
        <div style="display:flex;flex-direction:column;gap:8px;justify-content:center">
          <label style="display:flex;align-items:center;gap:8px;font-weight:600"><input type="checkbox" name="create_categories" checked style="width:auto;margin:0"> Create missing categories automatically</label>
          <label style="display:flex;align-items:center;gap:8px;font-weight:600"><input type="checkbox" name="create_brands" checked style="width:auto;margin:0"> Create missing brands automatically</label>
        </div>
      </div>

      <div style="margin-top:16px">
        <button type="submit" class="btn-add"><i class="fa-solid fa-upload"></i> Import Items</button>
      </div>
    </form>

    <details style="margin-top:18px">
      <summary style="cursor:pointer;font-weight:700">How to prepare the file (columns)</summary>
      <div class="hint" style="font-size:13px;line-height:1.7;margin-top:10px">
        Make your list in Excel / Google Sheets, then save it as <b>CSV UTF-8</b>. The first row must contain column names.
        Only <b>sku</b>, <b>name</b> and <b>selling_price</b> are required for new items — every other column is optional.
        Variations and images are <b>not</b> imported — add those later from the item's Edit page.
      </div>
      <table style="margin-top:10px;font-size:13px">
        <thead><tr><th>Column</th><th>What to put</th></tr></thead>
        <tbody>
          <tr><td><b>sku</b> *</td><td>Unique item code (max 60). Used to match existing items.</td></tr>
          <tr><td><b>name</b> *</td><td>Item name (max 300). Required for new items.</td></tr>
          <tr><td>category</td><td>Category name, or a path like <code>Lights &gt; Headlights</code>. A category ID number also works.</td></tr>
          <tr><td>brand</td><td>Brand name.</td></tr>
          <tr><td>description</td><td>Plain text (line breaks kept) or simple HTML.</td></tr>
          <tr><td>condition</td><td><code>new</code> or <code>used</code> / <code>old</code> (default new).</td></tr>
          <tr><td>warranty</td><td>e.g. 6 Months Warranty.</td></tr>
          <tr><td>stock_status</td><td><code>in_stock</code>, <code>out_of_stock</code> or <code>pre_order</code> (default in_stock).</td></tr>
          <tr><td>stock_qty</td><td>Whole number.</td></tr>
          <tr><td>cost_price / <b>selling_price</b> * / special_price / koko_price</td><td>Numbers — "Rs" and commas are fine (e.g. <code>Rs 3,500.00</code>). Special price must not be higher than the selling price.</td></tr>
          <tr><td>flash_sale / top_item</td><td><code>yes</code> / <code>no</code> — show in homepage Flash Sale / Top Selling.</td></tr>
        </tbody>
      </table>
      <p class="hint" style="font-size:13px">When <b>updating</b> an existing SKU, blank cells are ignored — the existing value is kept.</p>
    </details>
  </div>
</div>

<!-- ===== 2. HISTORY / UNDO ===== -->
<div class="panel">
  <div class="panel-head"><h3><i class="fa-solid fa-clock-rotate-left"></i> 2. Import History</h3></div>
  <div class="panel-body" style="padding:0">
    <table>
      <thead><tr><th>#</th><th>File</th><th>Date</th><th>Rows</th><th>Created</th><th>Updated</th><th>Skipped</th><th>Problems</th><th>Items still from this import</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($batches as $b): ?>
        <tr>
          <td><?= (int)$b['id'] ?></td>
          <td><b><?= e($b['file_name']) ?></b><?php if ($b['admin_name']): ?><br><span class="crumb">by <?= e($b['admin_name']) ?></span><?php endif; ?></td>
          <td style="font-size:12px"><?= e(date('d M Y, h:i A', strtotime($b['created_at']))) ?></td>
          <td><?= (int)$b['total_rows'] ?></td>
          <td><span class="tag tag-on"><?= (int)$b['created_count'] ?></span></td>
          <td><span class="tag tag-lvl"><?= (int)$b['updated_count'] ?></span></td>
          <td><?= (int)$b['skipped_count'] ?></td>
          <td><?= (int)$b['error_count'] ? '<span class="tag tag-off">' . (int)$b['error_count'] . '</span>' : '0' ?></td>
          <td><b><?= (int)$b['live_items'] ?></b></td>
          <td>
            <div class="row-actions">
              <?php if ((int)$b['live_items'] > 0): ?>
              <form method="post" style="display:inline" onsubmit="return confirm('Delete the <?= (int)$b['live_items'] ?> item(s) created by import #<?= (int)$b['id'] ?>?\nItems it only updated are NOT touched. This cannot be undone.');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete_batch_items">
                <input type="hidden" name="batch_id" value="<?= (int)$b['id'] ?>">
                <button class="mini-btn del" type="submit"><i class="fa-solid fa-trash"></i> Delete Imported Items</button>
              </form>
              <?php endif; ?>
              <form method="post" style="display:inline" onsubmit="return confirm('Remove import #<?= (int)$b['id'] ?> from the history? The items are kept.');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="forget_batch">
                <input type="hidden" name="batch_id" value="<?= (int)$b['id'] ?>">
                <button class="mini-btn toggle" type="submit" title="Remove log entry only">Remove Log</button>
              </form>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$batches): ?>
        <tr><td colspan="10" style="color:var(--muted)">No imports yet.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ===== 3. DELETE BY CSV ===== -->
<div class="panel">
  <div class="panel-head"><h3><i class="fa-solid fa-trash-can"></i> 3. Delete Items Using a CSV</h3></div>
  <div class="panel-body">
    <p class="hint" style="font-size:13px;margin:0 0 12px">Upload a CSV with a <b>sku</b> column (or just one column of SKU codes). Every matching item is deleted together with its images and variations.</p>
    <form method="post" enctype="multipart/form-data" onsubmit="return confirm('Delete every item whose SKU is listed in this file? This cannot be undone.');">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="delete_by_csv">
      <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
        <input type="file" name="delete_file" accept=".csv,.txt,text/csv" required>
        <button type="submit" class="mini-btn del" style="padding:10px 16px"><i class="fa-solid fa-trash"></i> Delete Listed Items</button>
      </div>
    </form>
    <p class="hint" style="font-size:13px;margin-top:12px">To delete by ticking items or to delete everything at once, use the <a href="<?= BASE_URL ?>/admin/items" style="color:#b8963f;font-weight:700">Items page</a>.</p>
  </div>
</div>

<script>
function showCsvName(input, targetId){
  const f = input.files[0];
  document.getElementById(targetId).innerHTML = f ? '✅ <b>' + f.name.replace(/[<>&"]/g, '') + '</b> (' + Math.ceil(f.size / 1024) + ' KB) — ready to import' : '📄 Drag &amp; drop your CSV here, or click to choose a file';
}
(function(){
  const dz = document.getElementById('csvDrop'), input = document.getElementById('csvFile');
  ['dragenter','dragover'].forEach(ev => dz.addEventListener(ev, e => { e.preventDefault(); dz.classList.add('drag-over'); }));
  ['dragleave','drop'].forEach(ev => dz.addEventListener(ev, e => { e.preventDefault(); dz.classList.remove('drag-over'); }));
  dz.addEventListener('drop', e => {
    const f = e.dataTransfer.files[0];
    if (!f) return;
    const dt = new DataTransfer(); dt.items.add(f); input.files = dt.files;
    showCsvName(input, 'csvDropText');
  });
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
