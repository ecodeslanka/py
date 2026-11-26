<?php
// Automatic, idempotent schema upgrades. Runs once (schema_v setting).
const SCHEMA_VERSION = 5;
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
    // v4: AI chatbot history
    db()->exec('CREATE TABLE IF NOT EXISTS chat_messages (
      id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, role ENUM(\'user\',\'bot\') NOT NULL, content TEXT NOT NULL, image VARCHAR(255) NULL, lang CHAR(2) NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY (user_id, created_at), FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    // v5: past papers + MCQ quizzes
    db()->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS papers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  year SMALLINT NOT NULL,
  name VARCHAR(160) NOT NULL,
  description VARCHAR(255) NULL,
  batch_id INT NULL,
  paper_file VARCHAR(255) NOT NULL,
  paper_name VARCHAR(255) NULL,
  scheme_file VARCHAR(255) NULL,
  scheme_name VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY (year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    db()->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS quizzes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(200) NOT NULL,
  description TEXT NULL,
  mode ENUM('exam','practice') NOT NULL DEFAULT 'exam',
  audience ENUM('all','batch','lesson') NOT NULL DEFAULT 'all',
  batch_id INT NULL,
  lesson_id INT NULL,
  open_at DATETIME NULL,
  close_at DATETIME NULL,
  duration_min INT NOT NULL DEFAULT 0,
  attempts INT NOT NULL DEFAULT 1,
  show_answers ENUM('after_submit','after_close','never') NOT NULL DEFAULT 'after_submit',
  shuffle TINYINT(1) NOT NULL DEFAULT 0,
  status ENUM('draft','published') NOT NULL DEFAULT 'draft',
  source_paper_id INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    db()->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS quiz_questions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  quiz_id INT NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  question TEXT NOT NULL,
  options TEXT NOT NULL,
  correct TINYINT NOT NULL DEFAULT 0,
  explanation TEXT NULL,
  ai_conf TINYINT NULL,
  reviewed TINYINT(1) NOT NULL DEFAULT 1,
  KEY (quiz_id, sort_order),
  FOREIGN KEY (quiz_id) REFERENCES quizzes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    db()->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS quiz_attempts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  quiz_id INT NOT NULL,
  user_id INT NOT NULL,
  attempt_no INT NOT NULL DEFAULT 1,
  started_at DATETIME NOT NULL,
  submitted_at DATETIME NULL,
  score INT NOT NULL DEFAULT 0,
  total INT NOT NULL DEFAULT 0,
  KEY (quiz_id, user_id),
  FOREIGN KEY (quiz_id) REFERENCES quizzes(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    db()->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS quiz_answers (
  attempt_id INT NOT NULL,
  question_id INT NOT NULL,
  chosen TINYINT NULL,
  is_correct TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (attempt_id, question_id),
  FOREIGN KEY (attempt_id) REFERENCES quiz_attempts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    set_setting('schema_v', (string)SCHEMA_VERSION);
}
