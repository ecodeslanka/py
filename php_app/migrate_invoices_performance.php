<?php
/**
 * ONE-TIME performance migration for invoices.php / invoices_data.php.
 *
 * What it does:
 *   1. Adds paid_amount_cache / pay_count_cache columns to field_summary_details
 *      and backfills them from invoice_payments (one full aggregation, done once).
 *   2. Adds indexes on every column used in WHERE/JOIN/ORDER BY by invoices_data.php.
 *   3. Creates triggers on invoice_payments (INSERT/UPDATE/DELETE) that keep the
 *      cached columns in sync automatically, so save_payment.php, delete_payment.php,
 *      reverse_payment.php, update_payment.php etc. do NOT need to be touched.
 *
 * Run this once from the browser (e.g. https://yourdomain/migrate_invoices_performance.php)
 * or via CLI (php migrate_invoices_performance.php), then you can delete this file.
 * Safe to re-run: every step checks "does this already exist?" before changing anything.
 */

include 'config.php';
header('Content-Type: text/plain');

function log_line($msg) { echo $msg . "\n"; @ob_flush(); @flush(); }

function index_exists($conn, $table, $indexName) {
    $r = mysqli_query($conn, "SHOW INDEX FROM `$table` WHERE Key_name = '$indexName'");
    return $r && mysqli_num_rows($r) > 0;
}
function column_exists($conn, $table, $col) {
    $r = mysqli_query($conn, "SHOW COLUMNS FROM `$table` LIKE '$col'");
    return $r && mysqli_num_rows($r) > 0;
}
function table_exists($conn, $table) {
    $r = mysqli_query($conn, "SHOW TABLES LIKE '$table'");
    return $r && mysqli_num_rows($r) > 0;
}
function add_index($conn, $table, $indexName, $cols) {
    if (!table_exists($conn, $table)) { log_line("  ! skip $table.$indexName — table not found"); return; }
    if (index_exists($conn, $table, $indexName)) { log_line("  = $table.$indexName already exists"); return; }
    $sql = "ALTER TABLE `$table` ADD INDEX `$indexName` ($cols)";
    if (mysqli_query($conn, $sql)) log_line("  + added index $table.$indexName");
    else log_line("  ! FAILED $table.$indexName — " . mysqli_error($conn));
}

log_line("=== Step 1: cached balance columns on field_summary_details ===");
if (!column_exists($conn, 'field_summary_details', 'to_be_delivery')) {
    mysqli_query($conn, "ALTER TABLE field_summary_details ADD COLUMN to_be_delivery TINYINT(1) NOT NULL DEFAULT 0");
    log_line("  + to_be_delivery column added");
}
if (!column_exists($conn, 'field_summary_details', 'paid_amount_cache')) {
    mysqli_query($conn, "ALTER TABLE field_summary_details
        ADD COLUMN paid_amount_cache DECIMAL(14,2) NOT NULL DEFAULT 0.00,
        ADD COLUMN pay_count_cache   INT NOT NULL DEFAULT 0");
    log_line("  + columns added");
} else {
    log_line("  = columns already exist");
}

log_line("=== Step 2: backfill cached columns from invoice_payments (runs once) ===");
$ok = mysqli_query($conn, "
    UPDATE field_summary_details fsd
    LEFT JOIN (
        SELECT field_summary_detail_id, SUM(amount) AS paid_amount, COUNT(*) AS pay_count
        FROM invoice_payments
        GROUP BY field_summary_detail_id
    ) pagg ON pagg.field_summary_detail_id = fsd.id
    SET fsd.paid_amount_cache = COALESCE(pagg.paid_amount, 0),
        fsd.pay_count_cache   = COALESCE(pagg.pay_count, 0)
");
log_line($ok ? "  + backfill complete (" . mysqli_affected_rows($conn) . " rows touched)"
             : "  ! backfill FAILED — " . mysqli_error($conn));

log_line("=== Step 3: indexes ===");
add_index($conn, 'field_summary_details', 'idx_route', 'route');
add_index($conn, 'field_summary_details', 'idx_tcode', 't_code');
add_index($conn, 'field_summary_details', 'idx_invoice_num', 'invoice_num');
add_index($conn, 'field_summary_details', 'idx_fsid', 'field_summary_id');
add_index($conn, 'field_summary_details', 'idx_tbd', 'to_be_delivery');
add_index($conn, 'field_summary', 'idx_delivery_date', 'delivery_date');
add_index($conn, 'field_summary', 'idx_sr_code', 'sr_code');
add_index($conn, 'field_summary', 'idx_fs_code', 'field_summary_code');
add_index($conn, 'customers', 'idx_tcode', 't_code');
add_index($conn, 'customers', 'idx_payment_mode', 'payment_mode');
add_index($conn, 'invoice_payments', 'idx_det', 'field_summary_detail_id'); // usually already there
add_index($conn, 'secondary_invoice_import_details', 'idx_bill_lookup', 'bill_no,delivery_date,status');
add_index($conn, 'loading_summary_import_details', 'idx_bill_lookup', 'bill_no,delivery_date,sales_person_code');

log_line("=== Step 4: triggers to keep cache in sync automatically ===");
mysqli_query($conn, "DROP TRIGGER IF EXISTS trg_ip_ai");
mysqli_query($conn, "DROP TRIGGER IF EXISTS trg_ip_au");
mysqli_query($conn, "DROP TRIGGER IF EXISTS trg_ip_ad");

$ok1 = mysqli_query($conn, "
    CREATE TRIGGER trg_ip_ai AFTER INSERT ON invoice_payments
    FOR EACH ROW
    UPDATE field_summary_details
    SET paid_amount_cache = (SELECT COALESCE(SUM(amount),0) FROM invoice_payments WHERE field_summary_detail_id = NEW.field_summary_detail_id),
        pay_count_cache   = (SELECT COUNT(*) FROM invoice_payments WHERE field_summary_detail_id = NEW.field_summary_detail_id)
    WHERE id = NEW.field_summary_detail_id
");
log_line($ok1 ? "  + trg_ip_ai (INSERT) created" : "  ! FAILED trg_ip_ai — " . mysqli_error($conn));

$ok2 = mysqli_query($conn, "
    CREATE TRIGGER trg_ip_au AFTER UPDATE ON invoice_payments
    FOR EACH ROW
    BEGIN
        UPDATE field_summary_details
        SET paid_amount_cache = (SELECT COALESCE(SUM(amount),0) FROM invoice_payments WHERE field_summary_detail_id = NEW.field_summary_detail_id),
            pay_count_cache   = (SELECT COUNT(*) FROM invoice_payments WHERE field_summary_detail_id = NEW.field_summary_detail_id)
        WHERE id = NEW.field_summary_detail_id;
        IF (OLD.field_summary_detail_id <> NEW.field_summary_detail_id) THEN
            UPDATE field_summary_details
            SET paid_amount_cache = (SELECT COALESCE(SUM(amount),0) FROM invoice_payments WHERE field_summary_detail_id = OLD.field_summary_detail_id),
                pay_count_cache   = (SELECT COUNT(*) FROM invoice_payments WHERE field_summary_detail_id = OLD.field_summary_detail_id)
            WHERE id = OLD.field_summary_detail_id;
        END IF;
    END
");
log_line($ok2 ? "  + trg_ip_au (UPDATE) created" : "  ! FAILED trg_ip_au — " . mysqli_error($conn));

$ok3 = mysqli_query($conn, "
    CREATE TRIGGER trg_ip_ad AFTER DELETE ON invoice_payments
    FOR EACH ROW
    UPDATE field_summary_details
    SET paid_amount_cache = (SELECT COALESCE(SUM(amount),0) FROM invoice_payments WHERE field_summary_detail_id = OLD.field_summary_detail_id),
        pay_count_cache   = (SELECT COUNT(*) FROM invoice_payments WHERE field_summary_detail_id = OLD.field_summary_detail_id)
    WHERE id = OLD.field_summary_detail_id
");
log_line($ok3 ? "  + trg_ip_ad (DELETE) created" : "  ! FAILED trg_ip_ad — " . mysqli_error($conn));

log_line("");
log_line("=== DONE ===");
log_line("Now replace invoices_data.php with the optimized version, then delete this migration file.");
