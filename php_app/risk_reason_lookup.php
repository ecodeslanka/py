<?php
/**
 * risk_reason_lookup.php
 * ─────────────────────────────────────────────────────────────────────
 * Shared helper: the real reason a customer counts as risky, taken from
 * the Customer Risk report (customer_credit_risk_report.php) itself.
 *
 * That report already works out, for every customer with money outstanding,
 * which single signal (return cheques, invoice aging, over their credit limit,
 * how long since their last payment, emergency credit history, cheque aging,
 * cheque policy, canceled amounts, send-back cheques) contributes the most
 * to their risk score, plus a human-readable sentence explaining it
 * ($reasons[$top_driver] in that file). Recomputing all of that here from
 * scratch would risk quietly drifting out of step with that report, so
 * instead this asks that report's own "risk_list" endpoint for the answer,
 * as the current user, and reuses its text.
 *
 * If a flagged customer has no outstanding balance right now, the Customer
 * Risk report does not list them at all (nothing left to score), so this
 * returns null and the caller should fall back to its own simpler text
 * (e.g. the plain blacklist reason).
 */

if (!function_exists('fetch_customer_risk_reason')) {
    function fetch_customer_risk_reason($t_code, $as_at_date = null) {
        static $cache = [];
        $t_code = trim((string)$t_code);
        if ($t_code === '') return null;
        $as_at_date = ($as_at_date && preg_match('/^\d{4}-\d{2}-\d{2}$/', $as_at_date)) ? $as_at_date : date('Y-m-d');
        $key = $t_code . '|' . $as_at_date;
        if (array_key_exists($key, $cache)) return $cache[$key];

        $result = null;
        if (function_exists('ini_get') && ini_get('allow_url_fopen') && session_id() !== '') {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host   = $_SERVER['HTTP_HOST'] ?? '';
            $dir    = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/x.php')), '/');
            if ($host !== '') {
                $url = $scheme . '://' . $host . $dir . '/customer_credit_risk_report.php?ajax=risk_list'
                     . '&to=' . urlencode($as_at_date) . '&search=' . urlencode($t_code);
                $cookie = session_name() . '=' . session_id();
                $ctx = stream_context_create(['http' => [
                    'method' => 'GET', 'header' => "Cookie: $cookie\r\n", 'timeout' => 4, 'ignore_errors' => true,
                ]]);
                /* PHP's default session handler holds an exclusive lock on the session file for as long as
                   the request runs. Calling back into another script that also starts the same session
                   would block on that same lock -- each waiting on the other -- until this request's
                   file_get_contents times out. Releasing the lock first, and reacquiring it once the
                   response is in, avoids that self-deadlock; $_SESSION keeps its values throughout. */
                $had_session = session_status() === PHP_SESSION_ACTIVE;
                if ($had_session) session_write_close();
                $body = @file_get_contents($url, false, $ctx);
                /* Only reacquire if it's still possible to (no output sent yet) -- $_SESSION keeps its
                   values in memory regardless, so a page already mid-render can safely keep reading it. */
                if ($had_session && !headers_sent()) session_start();
                if ($body !== false) {
                    $j = json_decode($body, true);
                    if (is_array($j) && isset($j['rows']) && is_array($j['rows'])) {
                        foreach ($j['rows'] as $row) {
                            if (isset($row['t_code']) && strcasecmp(trim((string)$row['t_code']), $t_code) === 0) {
                                $top = $row['top_driver'] ?? null;
                                $result = [
                                    'score'      => $row['risk_score'] ?? null,
                                    'band'       => $row['risk_band'] ?? null,
                                    'top_driver' => $top,
                                    'reason'     => ($top && isset($row['reasons'][$top])) ? $row['reasons'][$top] : '',
                                ];
                                break;
                            }
                        }
                    }
                }
            }
        }
        $cache[$key] = $result;
        return $result;
    }
}
