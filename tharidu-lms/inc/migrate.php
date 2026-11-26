<?php
// Automatic, idempotent schema upgrades. Runs once (schema_v setting).
const SCHEMA_VERSION = 3;
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
    // v3: richer materials, cart, grouped payments
    if (col_exists('materials', 'type')) db()->exec('ALTER TABLE materials MODIFY type VARCHAR(20) NOT NULL');
    if (!col_exists('materials', 'group_name')) db()->exec('ALTER TABLE materials ADD group_name VARCHAR(120) NULL');
    if (!col_exists('materials', 'sort_order')) db()->exec('ALTER TABLE materials ADD sort_order INT NOT NULL DEFAULT 0');
    if (!col_exists('materials', 'passcode')) db()->exec('ALTER TABLE materials ADD passcode VARCHAR(60) NULL');
    if (!col_exists('payments', 'order_ref')) db()->exec('ALTER TABLE payments ADD order_ref VARCHAR(24) NULL');
    db()->exec('CREATE TABLE IF NOT EXISTS cart (
      user_id INT NOT NULL, lesson_id INT NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (user_id, lesson_id),
      FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE, FOREIGN KEY (lesson_id) REFERENCES lessons(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    set_setting('schema_v', (string)SCHEMA_VERSION);
}
