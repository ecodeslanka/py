<?php
error_reporting(0);
ini_set('display_errors', 0);
ini_set('max_execution_time', 150);

ob_start();
include 'config.php';
ob_end_clean();
if (isset($conn) && $conn) { mysqli_report(MYSQLI_REPORT_OFF); }   // a failed query returns false (we report it) instead of throwing

header('Content-Type: application/json; charset=utf-8');

// ── Config ──────────────────────────────────────────────────────────────────
define('GEMINI_MODEL',   'gemini-2.5-flash');
define('MAX_ROWS',       200);
define('MAX_HISTORY',    8);
define('PROJECT_ROOT',   __DIR__);          // scans same folder as this file
define('MAX_FILE_BYTES', 90000);            // max chars read per PHP file
define('MAX_CODE_FILES', 60);              // cap total files fed to AI

// ── Load API key from ai_settings table ─────────────────────────────────────
function get_api_key(mysqli $conn): string {
    // Fallback chain: DB → env → hardcoded (dev only)
    $res = mysqli_query($conn, "SELECT `value` FROM ai_settings WHERE `key`='gemini_api_key' LIMIT 1");
    if ($res) {
        $row = mysqli_fetch_assoc($res);
        if (!empty($row['value'])) return trim($row['value']);
    }
    $env = getenv('GEMINI_API_KEY');
    if ($env) return $env;
    return ''; // no key found
}

// ── Build Gemini endpoint URL ────────────────────────────────────────────────
function gemini_endpoint(string $api_key): string {
    return 'https://generativelanguage.googleapis.com/v1beta/models/'
         . GEMINI_MODEL . ':generateContent?key=' . $api_key;
}

// ── Scan project PHP files and build code context ───────────────────────────
function get_project_context(string $question): string {
    $root  = PROJECT_ROOT;
    $files = [];

    // Recursively collect .php files (skip vendor, node_modules, hidden dirs)
    $skip_dirs = ['vendor', 'node_modules', '.git', 'cache', 'logs', 'storage'];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            function ($current, $key, $iterator) use ($skip_dirs) {
                if ($iterator->hasChildren()) {
                    $name = strtolower($current->getFilename());
                    return !in_array($name, $skip_dirs, true) && $name[0] !== '.';
                }
                return true;
            }
        )
    );

    foreach ($iterator as $file) {
        if ($file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }

    sort($files);

    // Build a directory listing (always included — lightweight)
    $rel_paths = array_map(fn($f) => ltrim(str_replace($root, '', $f), DIRECTORY_SEPARATOR), $files);
    $dir_listing = implode("\n", $rel_paths);

    // ── Keyword-based relevance scoring ──────────────────────────────────────
    // Pull words from the question to decide which files are most relevant
    $q_lower   = strtolower($question);
    $q_words   = preg_split('/\W+/', $q_lower, -1, PREG_SPLIT_NO_EMPTY);
    $stopwords = ['what', 'show', 'how', 'does', 'the', 'this', 'that',
                  'for', 'and', 'with', 'from', 'get', 'can', 'you',
                  'is', 'are', 'tell', 'me', 'about', 'give', 'list'];
    $keywords  = array_diff($q_words, $stopwords);

    // Score each file
    $scored = [];
    foreach ($files as $path) {
        $rel  = ltrim(str_replace($root, '', $path), DIRECTORY_SEPARATOR);
        $name = strtolower(basename($path, '.php'));
        $score = 0;
        foreach ($keywords as $kw) {
            if (str_contains($name, $kw))           $score += 10; // filename match
            if (str_contains(strtolower($rel), $kw)) $score += 5;  // path match
        }
        // Slight boost for common key pages
        $priority = ['dashboard','invoice','sales','import','route','customer',
                     'employee','payment','cheque','report','salary','ai'];
        foreach ($priority as $p) {
            if (str_contains($name, $p)) $score += 2;
        }
        // a sidebar page whose label matches the question is the page the user means
        if (function_exists('rbac_menu_pages')) {
            static $menuLabels = null;
            if ($menuLabels === null) { $menuLabels = []; foreach (rbac_menu_pages() as $pg => $mm) $menuLabels[strtolower($pg)] = strtolower($mm['label'] . ' ' . $mm['menu'] . ' ' . $mm['group']); }
            if (isset($menuLabels[$name])) foreach ($keywords as $kw) if (strlen($kw) > 2 && str_contains($menuLabels[$name], $kw)) $score += 12;
        }
        $scored[$path] = $score;
    }
    arsort($scored);

    // ── Read top-scored files (up to MAX_CODE_FILES) ──────────────────────────
    $included = [];
    $total_bytes = 0;
    $limit_bytes = 120000; // ~120 KB total code context cap

    foreach (array_keys($scored) as $path) {
        if (count($included) >= MAX_CODE_FILES) break;
        if ($total_bytes >= $limit_bytes) break;

        $rel     = ltrim(str_replace($root, '', $path), DIRECTORY_SEPARATOR);
        $content = @file_get_contents($path, false, null, 0, MAX_FILE_BYTES);
        if ($content === false) continue;

        // Strip PHP doc comments and blank lines for compactness
        $content = preg_replace('/\/\*\*[\s\S]*?\*\//', '', $content);   // strip /** ... */
        $content = preg_replace('/^\s*\/\/.*$/m', '', $content);          // strip // line comments
        $content = preg_replace('/\n{3,}/', "\n\n", $content);            // collapse blank lines
        $content = trim($content);

        $chunk   = "=== FILE: $rel ===\n$content\n";
        $total_bytes += strlen($chunk);
        $included[$rel] = $chunk;
    }

    // Build the context string
    $ctx  = "=== PROJECT DIRECTORY (all PHP pages) ===\n$dir_listing\n\n";
    $ctx .= "=== SOURCE CODE (most relevant files for this question) ===\n";
    foreach ($included as $rel => $code) {
        $ctx .= $code . "\n";
    }
    $ctx .= "\n(Total files in project: " . count($files) . ". Showing " . count($included) . " most relevant.)\n";

    return $ctx;
}

// ── Full DB Schema (hardcoded for speed & accuracy) ─────────────────────────
function get_db_schema(): string {
    return <<<'SCHEMA'
DATABASE: u645685294_ylerp  (Sales/Distribution ERP — Sri Lanka)

=== CORE SALES TABLES ===

TABLE: secondary_invoice_import_details  [PRIMARY SALES DATA — most queries start here]
  id (int)
  import_id (int) → secondary_invoice_imports.id
  sales_person_code (varchar 100)       -- SR code e.g. SMN00001
  t_code (varchar 50)                   -- Customer T-code
  route_code (varchar 50)               -- Route code e.g. YL01
  bill_no (varchar 100)                 -- Invoice/bill number
  bill_date (date)                      -- Date of the bill
  outlet_code (varchar 100)
  party_name (varchar 255)              -- Customer shop name
  free_qty (decimal 12,2)
  gross_sales (decimal 12,2)            -- Gross sales value
  scheme_disc (decimal 12,2)            -- Scheme discount
  rs_discount (decimal 12,2)            -- RS discount
  tot_disc (decimal 12,2)               -- Total discount
  total_discount (decimal 12,2)
  good_returns_value (decimal 12,2)     -- Good returns
  damage_expiry_shortage_value (decimal 12,2)
  final_bill_amount (decimal 12,2)      -- Net amount after all deductions
  delivery_person (varchar 255)
  delivery_date (date)
  t_code_valid (tinyint)                -- 1=valid, 0=invalid
  route_valid (tinyint)                 -- 1=valid, 0=invalid
  customer_name (varchar 255)
  route_name (varchar 255)
  status (enum: pending/imported/failed)
  error_message (text)
  created_at (timestamp)

TABLE: secondary_invoice_imports  [Import batch header]
  id (int)
  delivery_date (date)
  filename (varchar 255)
  total_records (int)
  imported_records (int)
  failed_records (int)
  status (enum: pending/processing/completed/failed)
  imported_by (int)
  imported_at (timestamp)

TABLE: loading_summary_import_details  [Loading/dispatch level sales detail]
  id, import_id, bill_no, bill_date, outlet_code, t_code, route_code, party_name,
  free_qty, gross_sales, scheme_disc, rs_discount, tot_disc, total_discount,
  good_returns_value, damage_expiry_shortage_value, sales_person_code,
  final_bill_amount, delivery_date, t_code_valid, route_valid, customer_name,
  route_name, status, error_message, created_at

TABLE: loading_summary_imports  [Loading batch header]
  id, delivery_date, filename, total_records, imported_records, failed_records,
  status, imported_by, imported_at

TABLE: loading_summary  [SR-level daily loading summary]
  id, delivery_date, sales_person_code, cc_employee_id, lorry_id,
  no_of_ports, no_of_bills, outlet_count,
  gross_invoice_value, scheme_discount, cash_discount_rs, cash_discount_tot,
  market_return_value, damage_expiry_shortage, net_invoice_value,
  cancelled_bill_value, adj_scheme_discount, adj_cash_discount_rs,
  adj_cash_discount_tot, adj_tot_dis, adj_market_return,
  adj_damage_expiry_shortage, final_bill_value,
  secondary_invoice_value, over_under_charge, created_at

=== FIELD SUMMARY TABLES ===

TABLE: field_summary
  id, field_summary_code, delivery_date, route, sr_code, total_invoices,
  total_net_value, total_scheme_discount, total_promotion_discount,
  total_tot_dis, total_market_return, total_damage_adjustment,
  total_cancel_value, total_adjust_net_value, total_ikea_value,
  total_short_excess, status, created_at, updated_at

TABLE: field_summary_details
  id, field_summary_id, invoice_num, t_code, customer_name, route,
  net_value, scheme_discount, promotion_discount, tot_dis, market_return,
  damage_adjustment, cancel_value, adjust_net_value, ikea_value,
  short_excess, payment_status, delivery_person, is_special_credit,
  bill_verified, to_be_delivery, created_at

=== PAYMENT TABLES ===

TABLE: invoice_payments
  id, field_summary_id, field_summary_detail_id, t_code, invoice_num,
  payment_method (cash/cheque/credit), payment_date, amount, amount_to_bank,
  reference_no, collected_by, cheque_mode, remarks, is_reversed,
  reversed_at, payment_source, created_at

TABLE: cheques
  id, invoice_payment_id, field_summary_id, t_code, cheque_no, cheque_date,
  amount, total_amount, bank_code, bank_name, branch_code, branch_name,
  status (pending/deposited/cleared/returned/sent_back), received_date,
  due_date, bulk_deposit, acc_holder_name, verified, sent_back_reason,
  created_at, updated_at

TABLE: credit_notes
  id, field_summary_detail_id, amount, reason, note_date, is_deleted, created_at

=== MASTER DATA TABLES ===

TABLE: customers
  id, t_code, shop_name, company_id, branch_id, route, address,
  telephone_number, primary_channel, channel,
  payment_mode (cash/credit/cheque), credit_limit, bill_to_bill_acceptance,
  credit_days, active, created_at, updated_at

TABLE: routes
  id, route_code, route_name, company_id, branch_id, active, created_at, updated_at

TABLE: employees
  id, employee_id, custom_code, company_id, branch_id, employee_full_name,
  name_with_initials, designation_id, staff_category_id, tr_code,
  date_of_birth, date_of_join, gender, telephone_mobile, bank_code,
  bank_branch_code, account_number, active, created_at, updated_at

TABLE: items, companies, branches, vehicles

=== FINANCIAL / SETTLEMENT TABLES ===

TABLE: primary_invoices
  id, invoice_date, invoice_no, invoice_capture_date, grn_date, dp_days,
  company, invoice_value, paid, scheduled_due_date, scheduled_banking_date,
  created_at, updated_at

TABLE: damage_returns_cl, debit_note, bo_bank_deposits, bo_cash_balance,
       bo_cash_deposits, cc_cash_deposits, cc_shortage_recovery

=== HR / PAYROLL TABLES ===

TABLE: salary_advances, salary_increments, loan_requests, leave_applications,
       payroll_periods, payroll_payments_log, employee_incentives,
       cash_shortage_deductions, drivers_loyalty

=== CHEQUE MANAGEMENT TABLES ===

TABLE: cheque_deposit_letters, cheque_deposit_letter_items, cheque_recon_uploads,
       cheque_recon_upload_items, cheque_verify_batches, cheque_verify_batch_items,
       cheque_return_charges, cheque_sb_settlement_payments,
       cheque_settlement_payments, cheque_logs, daily_cheque_reports,
       daily_cheque_report_items

=== SETTLEMENT TABLES ===

TABLE: invoice_payment_cheques, dl_settlements, dn_settlements, drcl_settlements,
       pi_settlements, pscl_settlements, psr_settlements, rc_settlement_payments,
       sricc_settlements, sr_incentive_cc, scheme_discount_settlements,
       others_cc, others_cc_settlements, cash_summary_pay_allocations,
       payment_reversals, payment_reversal_cheques

=== SCHEME / DISCOUNT TABLES ===

TABLE: scheme_discount_imports, scheme_discount_import_details, credit_requests,
       credit_bill_images, credit_bill_issues, credit_bill_issue_items,
       credit_documents, emergency_credit_reasons

=== USER / ACCESS TABLES ===

TABLE: users, roles, permissions, user_branches, user_companies,
       login_logs, logout_logs, remember_tokens

=== REFERENCE TABLES ===

TABLE: banks (54 Sri Lankan banks), bank_branches, designations, staff_categories,
       incentive_types, public_holidays, send_back_cheque_reasons,
       epf_etf_settings, fingerprint_machines, company_bank_accounts,
       company_settings, customer_bank_accounts, customer_claim_certificates,
       customer_claim_imports

=== STL / PROMOTER / OTHER TABLES ===

TABLE: stl_records, stl_letters, stl_settings, stl_settlement_payments,
       promoters_salary_cl, primary_sales_returns, unloading_data,
       unloading_pay_transactions, unloading_summary_imports,
       unloading_summary_import_details, sscl_vat, sscl_vat_settlements,
       field_summary_deletion_cheques, field_summary_deletion_log,
       field_summary_deletion_payments, employee_logs, employee_documents,
       ai_settings

=== KEY RELATIONSHIPS ===
- secondary_invoice_import_details.import_id  → secondary_invoice_imports.id
- secondary_invoice_import_details.t_code     → customers.t_code
- secondary_invoice_import_details.route_code → routes.route_code
- loading_summary_import_details.import_id    → loading_summary_imports.id
- field_summary_details.field_summary_id      → field_summary.id
- invoice_payments.field_summary_detail_id    → field_summary_details.id
- cheques.invoice_payment_id                  → invoice_payments.id
- employees.custom_code = secondary_invoice_import_details.sales_person_code

=== NOTES ===
- Main sales analysis: use secondary_invoice_import_details
- For SR performance: GROUP BY sales_person_code
- For route analysis: GROUP BY route_code or route_name
- Dates stored as DATE — use DATE_FORMAT(), CURDATE(), MONTH(), YEAR()
- Invalid records: t_code_valid=0 OR route_valid=0 OR status='failed'
- Currency: Sri Lankan Rupees (LKR)
SCHEMA;
}

// ── Helpers ─────────────────────────────────────────────────────────────────
/** Gemini call. $messages: [{role, text, images?:[{mime,data}]}]. $opt: temperature, json (force JSON output), tokens */
function gemini_call(array $messages, string $system, string $endpoint, array $opt = []): string {
    $contents = [];
    foreach ($messages as $m) {
        $parts = [['text' => (string)$m['text']]];
        foreach (($m['images'] ?? []) as $im) $parts[] = ['inlineData' => ['mimeType' => $im['mime'], 'data' => $im['data']]];
        $contents[] = ['role' => $m['role'], 'parts' => $parts];
    }
    $body = ['contents' => $contents];
    if ($system) $body['systemInstruction'] = ['parts' => [['text' => $system]]];
    $body['generationConfig'] = ['temperature' => $opt['temperature'] ?? 0.2, 'maxOutputTokens' => $opt['tokens'] ?? 4096];
    if (!empty($opt['json'])) $body['generationConfig']['responseMimeType'] = 'application/json';

    $raw = ''; $err = '';
    for ($try = 0; $try < 2; $try++) {                       // one automatic retry on a network blip / overloaded model
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($body), CURLOPT_TIMEOUT => 55, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_SSL_VERIFYPEER => false]);
        $raw = curl_exec($ch); $err = curl_error($ch); $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        if (!$err && $http !== 429 && $http < 500) break;
        usleep(700000);
    }
    if ($err) throw new RuntimeException("cURL error: $err");
    $resp = json_decode($raw, true);
    if (isset($resp['error'])) throw new RuntimeException('Gemini API error: ' . ($resp['error']['message'] ?? json_encode($resp['error'])));
    $txt = '';
    foreach (($resp['candidates'][0]['content']['parts'] ?? []) as $p) $txt .= $p['text'] ?? '';
    return $txt;
}

/** tables / columns the assistant may never read or return (passwords, keys, tokens, sessions) */
function sensitive_re(): string {
    return '/\b(users|ai_settings|remember_tokens|password_resets|pw_reset\w*|login_attempts|sessions|email_settings|role_audit_log)\b|password|passwd|api_key|secret|token|smtp_pass/i';
}

function safe_query(mysqli $conn, string $sql): array {
    $sql = trim(preg_replace('/^\s*SQL:\s*/i', '', $sql));
    $sql = trim(preg_replace('/^```(?:sql)?|```$/mi', '', $sql));
    $sql = rtrim(trim($sql), ';');
    if (!preg_match('/^(select|with)\s/i', $sql)) throw new RuntimeException("Only SELECT queries are allowed.");
    if (strpos($sql, ';') !== false) throw new RuntimeException("Only one statement is allowed.");
    if (preg_match('/(--|#|\/\*)/', $sql)) throw new RuntimeException("Comments are not allowed in the query.");
    foreach (['DROP','DELETE','UPDATE','INSERT','ALTER','CREATE','TRUNCATE','EXEC','EXECUTE','REPLACE','GRANT','REVOKE','LOAD_FILE','OUTFILE','DUMPFILE','SLEEP','BENCHMARK','CALL','HANDLER','LOCK'] as $kw) {
        if (preg_match('/\b' . $kw . '\b/i', $sql)) throw new RuntimeException("Query contains blocked keyword: $kw");
    }
    if (preg_match(sensitive_re(), $sql)) throw new RuntimeException("That data (users / passwords / keys) is not available to the assistant.");
    mysqli_report(MYSQLI_REPORT_OFF);
    @mysqli_query($conn, "SET SESSION MAX_EXECUTION_TIME=20000");
    $result = mysqli_query($conn, $sql);
    if (!$result) throw new RuntimeException("Query failed: " . mysqli_error($conn));
    $rows = [];
    while ($row = mysqli_fetch_assoc($result)) { $rows[] = $row; if (count($rows) >= MAX_ROWS) break; }
    mysqli_free_result($result);
    return $rows;
}

function rows_to_text(array $rows): string {
    if (empty($rows)) return "(no rows returned)";
    $headers = array_keys($rows[0]);
    $lines   = [implode(' | ', $headers), str_repeat('-', 60)];
    foreach ($rows as $r) $lines[] = implode(' | ', array_map(fn($v) => $v ?? 'NULL', array_values($r)));
    return implode("\n", $lines);
}

function needs_code_context(string $message): bool {
    $kws = ['page', 'file', 'php', 'code', 'function', 'class', 'module', 'how does', 'how is', 'where is', 'which page', 'which file', 'system', 'feature', 'built',
            'implemented', 'work', 'logic', 'handler', 'import process', 'upload', 'script', 'chatbot', 'gemini', 'api key', 'settings', 'dashboard', 'menu', 'navigation',
            'sidebar', 'layout', 'login', 'auth', 'permission', 'role', 'access', 'folder', 'directory', 'structure', 'project', 'report', 'where can', 'open', 'find', 'button'];
    $lower = strtolower($message);
    foreach ($kws as $kw) if (str_contains($lower, $kw)) return true;
    return false;
}

// ── Live schema: every table / column that exists in THIS database (cached 15 min), minus the sensitive ones ──
function get_live_schema(mysqli $conn, string $hardcoded): string {
    $cache = rtrim(sys_get_temp_dir(), '/\\') . '/yms_bot_schema_' . md5(__DIR__) . '.txt';
    if (is_file($cache) && filemtime($cache) > time() - 900) return (string)@file_get_contents($cache);
    $db = ''; $r = mysqli_query($conn, "SELECT DATABASE()"); if ($r) { $db = (string)(mysqli_fetch_row($r)[0] ?? ''); }
    if ($db === '') return '';
    $e = mysqli_real_escape_string($conn, $db);
    $r = mysqli_query($conn, "SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = '$e' ORDER BY TABLE_NAME, ORDINAL_POSITION");
    if (!$r) return '';
    $t = [];
    while ($x = mysqli_fetch_row($r)) { if (preg_match(sensitive_re(), $x[0] . ' ' . $x[1])) continue; $t[$x[0]][] = $x[1] . ' ' . $x[2]; }
    $out = "=== LIVE SCHEMA (all other tables that exist in the database — exact names) ===\n";
    foreach ($t as $name => $cols) {
        if (preg_match('/TABLE:[^\n]*\b' . preg_quote($name, '/') . '\b/i', $hardcoded)) continue;     // already described above
        $out .= $name . ' (' . implode(', ', $cols) . ")\n";
    }
    @file_put_contents($cache, $out);
    return $out;
}

// ── System menu (header.php sidebar) the logged-in user is allowed to open ──
function menu_items(): array {
    $items = [];
    if (!function_exists('rbac_menu_pages')) return $items;
    foreach (rbac_menu_pages() as $page => $m) {
        if (function_exists('rbac_can') && !rbac_can('view', $page)) continue;
        $file = $page . '.php'; if (!is_file(__DIR__ . '/' . $file)) continue;
        $items[] = ['file' => $file, 'label' => $m['label'], 'section' => $m['section'], 'menu' => $m['menu'], 'group' => $m['group']];
    }
    return $items;
}
function menu_context(array $items): string {
    $o = "=== SYSTEM MENU (sidebar of the ERP — only pages this user may open; format: Section > Menu > Group > Page → file) ===\n";
    foreach ($items as $i) $o .= $i['section'] . ' > ' . $i['menu'] . ($i['group'] !== '' ? ' > ' . $i['group'] : '') . ' > ' . $i['label'] . ' → ' . $i['file'] . "\n";
    return $o;
}

// ── Image scan ────────────────────────────────────────────────────────────────
function clean_images($imgs): array {
    $out = [];
    foreach ((array)$imgs as $im) {
        if (count($out) >= 4) break;
        $mime = strtolower((string)($im['mime'] ?? 'image/jpeg')); $data = (string)($im['data'] ?? '');
        if (preg_match('/^data:([^;]+);base64,(.*)$/s', $data, $m)) { $mime = strtolower($m[1]); $data = $m[2]; }
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif'], true)) continue;
        $data = preg_replace('/\s+/', '', $data);
        if ($data === '' || strlen($data) > 7500000) continue;             // ≈ 5.5 MB per image after the browser's compression
        $out[] = ['mime' => $mime, 'data' => $data];
    }
    return $out;
}
function scan_prompt(string $mode): string {
    $base = "You read photographs / scans of business documents for a Sri Lankan sales & distribution ERP. Read EVERY character carefully. "
          . "Never guess: if a value is unreadable or missing use null and add a warning. Amounts are in LKR; keep digits exactly as printed (no rounding). Dates -> YYYY-MM-DD when the day, month and year are all readable.\n"
          . "Return ONLY JSON: {\"doc_type\":\"cheque|invoice|receipt|delivery_note|other\",\"confidence\":0-100,\"fields\":{...key:value...},\"items\":[{...row...}],\"text\":\"full readable text\",\"warnings\":[\"...\"]}.\n";
    $c = "For a CHEQUE fields must be: bank, branch, cheque_no (6 digits MICR), bank_code, branch_code, account_no, cheque_date, payee, amount_figures (number), amount_words, crossed (true/false, 'A/C Payee only'), signature_present (true/false), stamp_or_remarks. Warn when amount_figures and amount_words disagree, the date looks stale (older than 6 months) or post-dated, or the cheque looks altered.\n";
    $i = "For an INVOICE / BILL / RECEIPT fields must be: invoice_no, date, customer_name, t_code (or outlet code), route, salesperson, gross_total, discount_total, tax_total, net_total. items = one row per line: product_code, description, qty, unit_price, discount, amount. Warn when the line amounts do not add up to the total.\n";
    if ($mode === 'cheque') return $base . "The image is a CHEQUE.\n" . $c;
    if ($mode === 'invoice') return $base . "The image is an INVOICE / BILL.\n" . $i;
    if ($mode === 'document') return $base . "The image is a general document: fill fields with every labelled value you can read, items with any table rows, and text with the full text.\n";
    return $base . "First decide the doc_type, then extract it.\n" . $c . $i . "For anything else put every labelled value in fields and the table rows in items.\n";
}
function md_cell($v): string { if ($v === null || $v === '') return '—'; if (is_bool($v)) return $v ? 'Yes' : 'No'; if (is_array($v)) $v = json_encode($v, JSON_UNESCAPED_UNICODE); return str_replace(['|', "\n"], ['/', ' '], trim((string)$v)); }
function label_of(string $k): string { return ucwords(str_replace('_', ' ', $k)); }
/** look the scanned cheque / bill up in the system and report matches / mismatches */
function scan_crosscheck(mysqli $conn, array $S, array &$notes): array {
    $f = (array)($S['fields'] ?? []); $found = [];
    $q = function ($sql) use ($conn) { try { $r = mysqli_query($conn, $sql); } catch (Throwable $e) { return []; } $o = []; while ($r && ($x = mysqli_fetch_assoc($r))) $o[] = $x; return $o; };
    $e = fn($v) => mysqli_real_escape_string($conn, trim((string)$v));
    $num = fn($v) => is_numeric(str_replace(',', '', (string)$v)) ? round((float)str_replace(',', '', (string)$v), 2) : null;
    $chq = preg_replace('/\D/', '', (string)($f['cheque_no'] ?? ''));
    if (($S['doc_type'] ?? '') === 'cheque' && $chq !== '') {
        $rows = $q("SELECT cheque_no, bank_code, branch_code, t_code, cheque_date, status, COALESCE(total_amount, amount, 0) AS amount FROM cheques WHERE cheque_no = '" . $e($chq) . "' ORDER BY cheque_date DESC LIMIT 5");
        if ($rows) { $found['cheque'] = $rows; $a = $num($f['amount_figures'] ?? null);
            foreach ($rows as $r) if ($a !== null && abs((float)$r['amount'] - $a) > 0.5) $notes[] = "⚠️ Cheque $chq is in the system with amount " . number_format((float)$r['amount'], 2) . " but the image shows " . number_format($a, 2) . '.';
        } else $notes[] = "ℹ️ Cheque no $chq was not found in the system (not yet entered).";
    }
    $inv = trim((string)($f['invoice_no'] ?? ''));
    if ($inv !== '' && in_array(($S['doc_type'] ?? ''), ['invoice', 'receipt', 'delivery_note'], true)) {
        $rows = $q("SELECT bill_no, delivery_date, t_code, party_name, final_bill_amount, status FROM secondary_invoice_import_details WHERE bill_no = '" . $e($inv) . "' LIMIT 5");
        $rows2 = $q("SELECT fsd.invoice_num, fs.delivery_date, fsd.t_code FROM field_summary_details fsd INNER JOIN field_summary fs ON fs.id = fsd.field_summary_id WHERE fsd.invoice_num = '" . $e($inv) . "' LIMIT 5");
        if ($rows) { $found['secondary_invoice'] = $rows; $n = $num($f['net_total'] ?? null);
            foreach ($rows as $r) if ($n !== null && abs((float)$r['final_bill_amount'] - $n) > 0.5) $notes[] = "⚠️ Bill $inv: system final amount " . number_format((float)$r['final_bill_amount'], 2) . " ≠ image " . number_format($n, 2) . '.'; }
        if ($rows2) $found['invoice'] = $rows2;
        if (!$rows && !$rows2) $notes[] = "ℹ️ Bill no $inv was not found in the system.";
    }
    return $found;
}
function scan_markdown(array $S, array $found, array $notes): string {
    $type = $S['doc_type'] ?? 'other'; $md = '**Scanned: ' . strtoupper(str_replace('_', ' ', $type)) . '** · confidence **' . (int)($S['confidence'] ?? 0) . "%**\n\n";
    $f = (array)($S['fields'] ?? []);
    if ($f) { $md .= "| Field | Value |\n|---|---|\n"; foreach ($f as $k => $v) $md .= '| ' . label_of((string)$k) . ' | ' . md_cell($v) . " |\n"; $md .= "\n"; }
    $items = array_values(array_filter((array)($S['items'] ?? []), 'is_array'));
    if ($items) { $cols = array_keys($items[0]); $md .= '| ' . implode(' | ', array_map('label_of', $cols)) . " |\n|" . str_repeat('---|', count($cols)) . "\n";
        foreach ($items as $it) { $md .= '| ' . implode(' | ', array_map(fn($c) => md_cell($it[$c] ?? null), $cols)) . " |\n"; } $md .= "\n"; }
    foreach ($found as $k => $rows) { if (!$rows) continue; $cols = array_keys($rows[0]); $md .= '**In the system (' . label_of($k) . ")**\n\n| " . implode(' | ', array_map('label_of', $cols)) . " |\n|" . str_repeat('---|', count($cols)) . "\n"; foreach ($rows as $r) $md .= '| ' . implode(' | ', array_map('md_cell', array_values($r))) . " |\n"; $md .= "\n"; }
    $w = array_merge(array_map(fn($x) => '⚠️ ' . $x, (array)($S['warnings'] ?? [])), $notes);
    foreach ($w as $x) $md .= '- ' . $x . "\n";
    if (!$f && !$items && !empty($S['text'])) $md .= "```\n" . $S['text'] . "\n```\n";
    return $md;
}

// ── Main ────────────────────────────────────────────────────────────────────
try {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = (string)($body['action'] ?? 'chat');

    if ($action === 'menu') {                                   // the sidebar the user may open (drawer "System menu")
        echo json_encode(['success' => true, 'items' => menu_items()], JSON_INVALID_UTF8_SUBSTITUTE); exit;
    }
    if ($action === 'export') {                                 // chat table → Excel
        $cols = array_values(array_map('strval', (array)($body['columns'] ?? []))); $rows = array_slice((array)($body['rows'] ?? []), 0, 5000);
        if (!$cols || !class_exists('ZipArchive')) { echo json_encode(['success' => false, 'message' => 'Nothing to export (or ZipArchive is missing).']); exit; }
        require_once __DIR__ . '/rbac_xlsx.php';
        $X = new MiniXlsx(); $sh = $X->sheet('Report'); $title = mb_substr((string)($body['title'] ?? 'Yelo AI report'), 0, 120);
        $sh->set(1, 1, $title, ['b' => 1, 'sz' => 14]); $sh->set(2, 1, 'Generated ' . date('d M Y H:i') . ' by Yelo AI', ['i' => 1, 'color' => '6B7280']);
        foreach ($cols as $i => $c) { $sh->set(4, $i + 1, $c, ['b' => 1, 'color' => 'FFFFFF', 'bg' => '1E3A8A', 'h' => 'center', 'border' => 1, 'wrap' => 1]); $sh->widths[$i + 1] = max(10, min(40, mb_strlen($c) + 4)); }
        foreach ($rows as $ri => $r) foreach ($cols as $i => $c) { $v = is_array($r) ? ($r[$c] ?? ($r[$i] ?? '')) : ''; $clean = str_replace(',', '', (string)$v);
            $sh->set($ri + 5, $i + 1, (is_numeric($clean) && !preg_match('/^0\d/', $clean)) ? (float)$clean : (string)$v, ['border' => 1] + ((is_numeric($clean) && !preg_match('/^0\d/', $clean)) ? ['h' => 'right', 'nf' => 4] : [])); }
        $sh->freeze = [4, 0]; $sh->filter = 'A4:' . MiniXlsx::col(count($cols)) . max(5, count($rows) + 4);
        $tmp = tempnam(sys_get_temp_dir(), 'bot'); $X->save($tmp);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'); header('Content-Disposition: attachment; filename="yelo_ai_report_' . date('Ymd_His') . '.xlsx"'); header('Content-Length: ' . filesize($tmp));
        readfile($tmp); @unlink($tmp); exit;
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') die(json_encode(['success' => false, 'message' => 'POST only']));

    $message = trim($body['message'] ?? '');
    $history = array_values(array_filter((array)($body['history'] ?? []), fn($h) => is_array($h) && isset($h['role'], $h['text']) && in_array($h['role'], ['user', 'model'], true)));
    $images  = clean_images($body['images'] ?? []);
    $mode    = in_array(($body['mode'] ?? 'auto'), ['auto', 'cheque', 'invoice', 'document'], true) ? $body['mode'] : 'auto';
    if ($message === '' && !$images) die(json_encode(['success' => false, 'message' => 'Empty message']));
    if (!$conn) die(json_encode(['success' => false, 'message' => 'DB connection failed']));
    if (session_status() === PHP_SESSION_NONE) @session_start();
    if (empty($_SESSION['user_id'])) { http_response_code(401); die(json_encode(['success' => false, 'message' => 'Your session has expired — please log in again.'])); }
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

    $api_key = get_api_key($conn);
    if (empty($api_key)) die(json_encode(['success' => false, 'message' => 'Gemini API key not configured. Please add it in AI Settings → ai_settings.php']));
    $endpoint = gemini_endpoint($api_key);
    $menu = menu_items();

    // ── IMAGE SCAN ────────────────────────────────────────────────────────────
    $scanMd = ''; $scanData = null; $found = [];
    if ($images) {
        $raw = gemini_call([['role' => 'user', 'text' => 'Extract everything from the attached image(s). Return the JSON only.', 'images' => $images]], scan_prompt($mode), $endpoint, ['json' => true, 'temperature' => 0.0, 'tokens' => 6000]);
        $S = json_decode(trim(preg_replace('/^```(?:json)?|```$/mi', '', trim($raw))), true);
        if (!is_array($S)) { echo json_encode(['success' => true, 'answer' => "I could not read that image clearly. Try a sharper, well-lit photo with the whole document in frame.\n\n```\n" . mb_substr($raw, 0, 600) . "\n```", 'sql' => '', 'row_count' => 0, 'mode' => 'scan']); exit; }
        $notes = []; $found = scan_crosscheck($conn, $S, $notes); $scanData = $S; $scanMd = scan_markdown($S, $found, $notes);
        $generic = '/^(scan|read|extract|ocr|check|what|this|details?|analy[sz]e|verify|ok|please|image|photo|cheque|invoice|bill|document)?[\s\.\!\?]*$/i';
        if ($message === '' || preg_match($generic, $message) || mb_strlen($message) < 12) {
            echo json_encode(['success' => true, 'answer' => $scanMd, 'sql' => '', 'explanation' => '', 'row_count' => 0, 'mode' => 'scan', 'scan' => $scanData]); exit;
        }
    }

    // ── Decide context ────────────────────────────────────────────────────────
    $use_code_context = needs_code_context($message);
    $schema       = get_db_schema();
    $liveSchema   = get_live_schema($conn, $schema);
    $menuCtx      = menu_context($menu);
    $code_context = $use_code_context ? get_project_context($message) : '';
    $today        = date('Y-m-d (l)');

    $systemPrompt = <<<SYS
You are the data + system assistant of a PHP Sales/Distribution ERP in Sri Lanka (Ratnapura region). Today is $today. Currency is LKR.
Knowledge sources, in order of trust:
1. DATABASE SCHEMA (exact table and column names — never invent a column):
$schema
$liveSchema
2. SYSTEM MENU (what exists in the sidebar and which file opens it):
$menuCtx
3. PROJECT SOURCE CODE:
$code_context

RULES
- DATA question → reply with ONE line `SQL: <single SELECT>` then `EXPLANATION: <one sentence>`. SELECT only, no semicolon, no comments, use only tables/columns from the schema, qualify columns when joining, always LIMIT 200 unless aggregating.
- Never read the users / password / token / API-key tables.
- SYSTEM / MENU / "where is" question → reply `NO_QUERY` (the answer is built from the menu + code).
- Date words: "today" = CURDATE(), "this month" = MONTH(x)=MONTH(CURDATE()) AND YEAR(x)=YEAR(CURDATE()), "yesterday" = CURDATE()-INTERVAL 1 DAY.
- Valid sales rows normally have status='imported'; say so if you filter on it.
SYS;

    // ── Step 1: SQL (with up to 2 automatic repairs when the query fails) ────
    $h = array_slice($history, -MAX_HISTORY);
    $ask = "User question: $message" . ($scanMd ? "\n\nAn image was scanned. Scan result:\n" . mb_substr($scanMd, 0, 3000) : '') . "\n\nDo you need a SQL query? If yes write it starting with SQL:";
    $queryRows = []; $queryText = ''; $explanation = ''; $sqlExecuted = ''; $sqlError = '';
    $msgs = array_merge($h, [['role' => 'user', 'text' => $ask]]);
    for ($attempt = 0; $attempt < 3; $attempt++) {
        $step1 = gemini_call($msgs, $systemPrompt, $endpoint, ['temperature' => 0.0, 'tokens' => 1500]);
        if (preg_match('/^\s*NO_QUERY/i', $step1)) { $sqlExecuted = ''; break; }
        $sqlExecuted = '';
        if (preg_match('/SQL:\s*([\s\S]+?)(?:EXPLANATION:|$)/i', $step1, $full)) $sqlExecuted = trim($full[1]);
        if (preg_match('/EXPLANATION:\s*(.+)/i', $step1, $em)) $explanation = trim($em[1]);
        if ($sqlExecuted === '') break;
        try { $queryRows = safe_query($conn, $sqlExecuted); $queryText = rows_to_text($queryRows); $sqlError = ''; break; }
        catch (RuntimeException $qe) {
            $sqlError = $qe->getMessage(); $queryRows = []; $queryText = '';
            $msgs[] = ['role' => 'model', 'text' => $step1];
            $msgs[] = ['role' => 'user', 'text' => "That query failed: $sqlError\nFix it using ONLY the exact table and column names of the schema, and reply again with SQL: ..."];
        }
    }
    if ($sqlError) $queryText = "Query error (after retries): $sqlError";

    // ── Step 2: the answer, built only from the real rows ───────────────────
    $dataSection = $queryText ? "\n\nDATA FROM DATABASE (" . count($queryRows) . " rows" . (count($queryRows) >= MAX_ROWS ? ', capped' : '') . "):\n$queryText" : "\n\n(No database query was needed.)";
    $codeSection = $code_context ? "\n\nPROJECT SOURCE CODE CONTEXT:\n$code_context" : '';
    $scanSection = $scanMd ? "\n\nSCANNED IMAGE RESULT (already verified against the system):\n$scanMd" : '';
    $answerPrompt = <<<SYS
You are a precise business analyst and PHP system analyst for a Sales/Distribution ERP in Sri Lanka. Today is $today. Currency LKR.
ACCURACY RULES
- Use ONLY numbers that appear in DATA FROM DATABASE / the scan result. Never estimate, extrapolate or invent a figure. If the data does not answer the question say so and say which table/page would.
- If a query error is shown, say the data could not be read and what to try.
- Present rows as a markdown table; bold the key numbers; format amounts with thousands separators (e.g. 1,250,430.50).
- Give a short insight (1–3 bullets) only when the data supports it.
- For menu / "where is" questions: name the exact menu path (Section > Menu > Page) from the SYSTEM MENU and finish with a line `PAGES: file1.php, file2.php` listing the pages involved. Only list files that exist in the menu.
- For code questions: name the files, the flow (form → handler → DB → output) and the key SQL / functions from the source.
- Be concise.
SYS;
    $menuForAnswer = $use_code_context ? "\n\n" . $menuCtx : '';
    $h2 = array_slice($history, -MAX_HISTORY);
    $h2[] = ['role' => 'user', 'text' => "User question: $message$dataSection$scanSection$codeSection$menuForAnswer\n\nPlease answer."];
    $finalAnswer = gemini_call($h2, $answerPrompt, $endpoint, ['temperature' => 0.2, 'tokens' => 3500]);
    if ($scanMd && stripos($finalAnswer, 'Scanned:') === false) $finalAnswer = $scanMd . "\n\n---\n\n" . $finalAnswer;

    // links: PAGES line + any menu file named in the answer
    $links = []; $byFile = []; foreach ($menu as $m) $byFile[strtolower($m['file'])] = $m;
    if (preg_match('/PAGES:\s*(.+)$/mi', $finalAnswer, $pm)) {
        foreach (preg_split('/[,\s]+/', $pm[1], -1, PREG_SPLIT_NO_EMPTY) as $f) { $k = strtolower(trim($f, " .`*")); if (isset($byFile[$k])) $links[$k] = ['label' => $byFile[$k]['label'], 'href' => $byFile[$k]['file']]; }
        $finalAnswer = trim(preg_replace('/^PAGES:.*$/mi', '', $finalAnswer));
    }
    if (!$links && $use_code_context) foreach ($byFile as $k => $m) { if (stripos($finalAnswer, $m['file']) !== false && count($links) < 6) $links[$k] = ['label' => $m['label'], 'href' => $m['file']]; }

    echo json_encode([
        'success' => true, 'answer' => $finalAnswer, 'sql' => $sqlExecuted, 'explanation' => $explanation, 'row_count' => count($queryRows),
        'code_context' => $use_code_context, 'links' => array_values($links), 'scan' => $scanData,
        'columns' => $queryRows ? array_keys($queryRows[0]) : [], 'rows' => array_slice($queryRows, 0, 200),
    ], JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);

} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
exit;
