-- =========================================================
-- 1) Scans table (skip if you already ran it)
-- =========================================================
CREATE TABLE IF NOT EXISTS field_summary_scans (
    id               INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
    field_summary_id INT           NOT NULL,
    file_name        VARCHAR(255)  NOT NULL,           -- stored (random) name on disk
    original_name    VARCHAR(255)  NOT NULL,           -- name the user uploaded
    file_type        ENUM('image','pdf') NOT NULL,
    mime_type        VARCHAR(100)  NOT NULL,
    file_size        INT UNSIGNED  NOT NULL DEFAULT 0,
    uploaded_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_fss_summary (field_summary_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =========================================================
-- 2) Scan status columns on field_summary
--    scan_count   = number of files uploaded (0 = not uploaded)
--    last_scan_at = time of the latest upload
-- =========================================================
ALTER TABLE field_summary
    ADD COLUMN scan_count   INT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN last_scan_at DATETIME NULL DEFAULT NULL,
    ADD INDEX idx_fs_scan_count (scan_count);

-- =========================================================
-- 3) Backfill existing rows from any scans already uploaded
-- =========================================================
UPDATE field_summary fs
LEFT JOIN (
    SELECT field_summary_id, COUNT(*) AS cnt, MAX(uploaded_at) AS last_at
    FROM field_summary_scans
    GROUP BY field_summary_id
) sc ON sc.field_summary_id = fs.id
SET fs.scan_count   = COALESCE(sc.cnt, 0),
    fs.last_scan_at = sc.last_at;
