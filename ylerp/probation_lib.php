<?php
/** probation_lib.php — probation end date and probation extensions of employees (used by view_employee.php and the reasons master) */
if (!function_exists('prob_ensure')) {
    define('PROB_DEFAULT_MONTHS', 6);   // probation end when none is saved: date of join + 6 months
    function prob_q($conn, $sql) { try { return mysqli_query($conn, $sql); } catch (Throwable $e) { return false; } }
    function prob_ensure($conn) {
        static $done = false; if ($done) return; $done = true;
        $r = prob_q($conn, "SHOW COLUMNS FROM employees LIKE 'probation_end_date'");
        if ($r && mysqli_num_rows($r) === 0) prob_q($conn, "ALTER TABLE employees ADD COLUMN probation_end_date DATE NULL DEFAULT NULL");
        prob_q($conn, "CREATE TABLE IF NOT EXISTS probation_extension_reasons (
            id INT AUTO_INCREMENT PRIMARY KEY, reason VARCHAR(255) NOT NULL, is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_by VARCHAR(100) NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq_reason (reason)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        prob_q($conn, "CREATE TABLE IF NOT EXISTS employee_probation_extensions (
            id INT AUTO_INCREMENT PRIMARY KEY, employee_id INT NOT NULL,
            from_date DATE NULL, to_date DATE NOT NULL, extended_months DECIMAL(5,1) NULL,
            reason_id INT NULL, reason VARCHAR(255) NULL, remarks TEXT NULL,
            document_path VARCHAR(500) NULL, document_name VARCHAR(255) NULL,
            created_by VARCHAR(100) NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, KEY k_emp (employee_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
    function prob_user() { return $_SESSION['username'] ?? $_SESSION['user_name'] ?? $_SESSION['full_name'] ?? $_SESSION['name'] ?? (isset($_SESSION['user_id']) ? 'User #' . $_SESSION['user_id'] : 'System'); }
    /** [end date (Y-m-d or ''), how it was found: saved | extended | default] */
    function prob_end($conn, $emp) {
        $id = (int)($emp['id'] ?? 0);
        $r = prob_q($conn, "SELECT to_date FROM employee_probation_extensions WHERE employee_id = $id ORDER BY to_date DESC, id DESC LIMIT 1");
        if ($r && ($x = mysqli_fetch_row($r)) && $x[0]) return [$x[0], 'extended'];
        if (!empty($emp['probation_end_date']) && $emp['probation_end_date'] !== '0000-00-00') return [$emp['probation_end_date'], 'saved'];
        if (!empty($emp['date_of_join']) && $emp['date_of_join'] !== '0000-00-00') return [date('Y-m-d', strtotime($emp['date_of_join'] . ' +' . PROB_DEFAULT_MONTHS . ' months')), 'default'];
        return ['', ''];
    }
    function prob_extensions($conn, $id) {
        $o = []; $r = prob_q($conn, "SELECT * FROM employee_probation_extensions WHERE employee_id = " . (int)$id . " ORDER BY to_date, id");
        while ($r && ($x = mysqli_fetch_assoc($r))) $o[] = $x; return $o;
    }
    function prob_reasons($conn, $all = false) {
        $o = []; $r = prob_q($conn, "SELECT * FROM probation_extension_reasons" . ($all ? '' : " WHERE is_active = 1") . " ORDER BY reason");
        while ($r && ($x = mysqli_fetch_assoc($r))) $o[] = $x; return $o;
    }
}
