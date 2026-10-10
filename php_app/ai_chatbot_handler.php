<?php
error_reporting(0);
ini_set('display_errors', 0);
ini_set('max_execution_time', 60);

ob_start();
include 'config.php';
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');

// ── Config ──────────────────────────────────────────────────────────────────
define('GEMINI_MODEL',   'gemini-2.5-flash');
define('MAX_ROWS',       200);
define('MAX_HISTORY',    6);
define('PROJECT_ROOT',   __DIR__);          // scans same folder as this file
define('MAX_FILE_BYTES', 80000);            // max chars read per PHP file
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
function gemini_call(array $messages, string $system, string $endpoint): string {
    $contents = [];
    foreach ($messages as $m) {
        $contents[] = ['role' => $m['role'], 'parts' => [['text' => $m['text']]]];
    }
    $body = ['contents' => $contents];
    if ($system) {
        $body['systemInstruction'] = ['parts' => [['text' => $system]]];
    }
    $body['generationConfig'] = ['temperature' => 0.3, 'maxOutputTokens' => 2048];

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => json_encode($body),
        CURLOPT_TIMEOUT        => 45,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err) throw new RuntimeException("cURL error: $err");
    $resp = json_decode($raw, true);
    if (isset($resp['error'])) {
        throw new RuntimeException('Gemini API error: ' . ($resp['error']['message'] ?? json_encode($resp['error'])));
    }
    return $resp['candidates'][0]['content']['parts'][0]['text'] ?? '';
}

function safe_query(mysqli $conn, string $sql): array {
    $trimmed = ltrim($sql);
    if (!preg_match('/^select\s/i', $trimmed)) {
        throw new RuntimeException("Only SELECT queries are allowed.");
    }
    $blocked = ['DROP','DELETE','UPDATE','INSERT','ALTER','CREATE','TRUNCATE','EXEC','EXECUTE'];
    foreach ($blocked as $kw) {
        if (preg_match('/\b' . $kw . '\b/i', $sql)) {
            throw new RuntimeException("Query contains blocked keyword: $kw");
        }
    }
    $result = mysqli_query($conn, $sql);
    if (!$result) throw new RuntimeException("Query failed: " . mysqli_error($conn));
    $rows = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $rows[] = $row;
        if (count($rows) >= MAX_ROWS) break;
    }
    return $rows;
}

function rows_to_text(array $rows): string {
    if (empty($rows)) return "(no rows returned)";
    $headers = array_keys($rows[0]);
    $lines   = [implode(' | ', $headers), str_repeat('-', 60)];
    foreach ($rows as $r) {
        $lines[] = implode(' | ', array_map(fn($v) => $v ?? 'NULL', array_values($r)));
    }
    return implode("\n", $lines);
}

// ── Detect question type: DB data vs code/system question ────────────────────
function needs_code_context(string $message): bool {
    $code_keywords = [
        'page', 'file', 'php', 'code', 'function', 'class', 'module',
        'how does', 'how is', 'where is', 'which page', 'which file',
        'system', 'feature', 'built', 'implemented', 'work', 'logic',
        'handler', 'controller', 'import process', 'upload', 'script',
        'ai chatbot', 'chatbot', 'gemini', 'api key', 'settings',
        'dashboard', 'menu', 'navigation', 'sidebar', 'layout',
        'login', 'auth', 'permission', 'role', 'access',
        'folder', 'directory', 'structure', 'project',
    ];
    $lower = strtolower($message);
    foreach ($code_keywords as $kw) {
        if (str_contains($lower, $kw)) return true;
    }
    return false;
}

// ── Main ────────────────────────────────────────────────────────────────────
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        die(json_encode(['success' => false, 'message' => 'POST only']));
    }

    $body    = json_decode(file_get_contents('php://input'), true);
    $message = trim($body['message']  ?? '');
    $history = $body['history']       ?? [];

    if (empty($message)) {
        die(json_encode(['success' => false, 'message' => 'Empty message']));
    }
    if (!$conn) {
        die(json_encode(['success' => false, 'message' => 'DB connection failed']));
    }

    // Load API key from DB
    $api_key = get_api_key($conn);
    if (empty($api_key)) {
        die(json_encode([
            'success' => false,
            'message' => 'Gemini API key not configured. Please add it in AI Settings → ai_settings.php'
        ]));
    }
    $endpoint = gemini_endpoint($api_key);

    // ── Decide context mode ───────────────────────────────────────────────────
    $use_code_context = needs_code_context($message);
    $schema           = get_db_schema();
    $code_context     = $use_code_context ? get_project_context($message) : '';

    // ── Step 1: Gemini decides if SQL needed + writes query ───────────────────
    $systemPrompt = <<<SYS
You are an intelligent business analyst assistant AND system analyst for a PHP-based Sales/Distribution ERP
in Sri Lanka (Ratnapura region). You have two knowledge sources:

1. DATABASE SCHEMA:
$schema

2. PROJECT SOURCE CODE (PHP pages/files):
$code_context

RULES:
- For DATA questions (sales figures, import summaries, customer stats, etc.):
  Output a SELECT query on the FIRST line starting with: SQL:
  Then: EXPLANATION: <one sentence>
  Only SELECT. Never DROP/DELETE/UPDATE/INSERT/ALTER/CREATE/TRUNCATE.
  Always add LIMIT 200 unless doing aggregation.

- For CODE/SYSTEM questions (how a page works, what a feature does, file structure, etc.):
  Output: NO_QUERY
  Answer will be provided using the source code context.

- For MIXED questions: generate SQL if data is needed AND explain the code flow.

Use exact column/table names from the schema. For date filters use CURDATE(), DATE_SUB(), MONTH(), YEAR().
SYS;

    $step1Messages   = array_slice($history, -MAX_HISTORY);
    $step1Messages[] = [
        'role' => 'user',
        'text' => "User question: $message\n\nDecide: do you need a SQL query? If yes, write it starting with SQL:"
    ];

    $step1        = gemini_call($step1Messages, $systemPrompt, $endpoint);
    $queryRows    = [];
    $queryText    = '';
    $explanation  = '';
    $sqlExecuted  = '';

    if (!preg_match('/^NO_QUERY/i', trim($step1))) {
        if (preg_match('/SQL:\s*([\s\S]+?)(?:EXPLANATION:|$)/i', $step1, $full)) {
            $sqlExecuted = trim($full[1]);
        } elseif (preg_match('/^SQL:\s*(.+)$/mi', $step1, $m)) {
            $sqlExecuted = trim($m[1]);
        }
        if (preg_match('/EXPLANATION:\s*(.+)/i', $step1, $em)) {
            $explanation = trim($em[1]);
        }
        if ($sqlExecuted) {
            try {
                $queryRows = safe_query($conn, $sqlExecuted);
                $queryText = rows_to_text($queryRows);
            } catch (RuntimeException $qe) {
                $queryText = "Query error: " . $qe->getMessage();
            }
        }
    }

    // ── Step 2: Generate the final answer ────────────────────────────────────
    $dataSection = $queryText
        ? "\n\nDATA FROM DATABASE:\n$queryText\n(Rows: " . count($queryRows) . ")"
        : "\n\n(No database query needed.)";

    $codeSection = $code_context
        ? "\n\nPROJECT SOURCE CODE CONTEXT:\n$code_context"
        : '';

    $answerPrompt = <<<SYS
You are a friendly expert business analyst AND PHP system analyst for a Sales/Distribution ERP in Sri Lanka.
You answer two types of questions:

TYPE 1 — DATA/SALES QUESTIONS (from live DB):
- Present data in markdown tables
- Bold key numbers with **value**
- Add brief business insights after tables
- Use ✅ ❌ ⚠️ 📊 📈 🚚 emojis sparingly
- Currency is LKR (Sri Lankan Rupees)

TYPE 2 — SYSTEM/CODE QUESTIONS (from PHP source files):
- Explain which PHP file(s) handle the feature
- Describe the logic flow (form → handler → DB → response)
- Mention key functions, variables, or SQL used in the code
- If multiple files are involved, list them clearly
- Use 📁 🔧 🖥️ 💡 emojis sparingly

If no data or code found, suggest alternative phrasings.
Always be concise but insightful.
SYS;

    $step2Messages   = array_slice($history, -MAX_HISTORY);
    $step2Messages[] = [
        'role' => 'user',
        'text' => "User question: $message$dataSection$codeSection\n\nPlease answer the question."
    ];

    $finalAnswer = gemini_call($step2Messages, $answerPrompt, $endpoint);

    echo json_encode([
        'success'      => true,
        'answer'       => $finalAnswer,
        'sql'          => $sqlExecuted,
        'explanation'  => $explanation,
        'row_count'    => count($queryRows),
        'code_context' => $use_code_context, // tells UI whether code mode was used
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
exit;
?>