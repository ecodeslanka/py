<?php
// Automatic, idempotent schema upgrades. Runs once (schema_v setting).
const SCHEMA_VERSION = 2;
function col_exists(string $t, string $c): bool {
    return (bool)val('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?', [$t, $c]);
}
function run_migrations(): void {
    db()->exec("CREATE TABLE IF NOT EXISTS classes (
      id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(120) NOT NULL, sort_order INT NOT NULL DEFAULT 0, active TINYINT(1) NOT NULL DEFAULT 1
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    if (!col_exists('lessons', 'class_id')) {
        db()->exec('ALTER TABLE lessons ADD class_id INT NULL');
        try { db()->exec('ALTER TABLE lessons ADD CONSTRAINT fk_lesson_class FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE SET NULL'); } catch (Throwable $e) {}
        // v1 stored a free-text venue on each lesson -> turn each distinct venue into a Class
        if (col_exists('lessons', 'venue')) {
            foreach (rows("SELECT DISTINCT venue FROM lessons WHERE venue IS NOT NULL AND venue<>''") as $r) {
                $id = val('SELECT id FROM classes WHERE name=?', [$r['venue']]);
                if (!$id) { q('INSERT INTO classes(name) VALUES(?)', [$r['venue']]); $id = db()->lastInsertId(); }
                q('UPDATE lessons SET class_id=? WHERE venue=?', [$id, $r['venue']]);
            }
        }
    }
    if (!col_exists('users', 'class_id')) {
        db()->exec('ALTER TABLE users ADD class_id INT NULL');
        try { db()->exec('ALTER TABLE users ADD CONSTRAINT fk_user_class FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE SET NULL'); } catch (Throwable $e) {}
    }
    set_setting('schema_v', (string)SCHEMA_VERSION);
}
