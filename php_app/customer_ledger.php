<?php
/* ══════════════════════════════════════════════════════════════
   CUSTOMER LEDGER   v1.2   (standalone page)

   Builds a running-balance statement for one customer (t_code):
     DEBIT  → every invoice in field_summary_details — amount is the
              "Ikea value" (final_bill_amount from a live lookup into
              secondary_invoice_import_details, matched by bill_no +
              delivery_date, status='imported' — the exact same
              live lookup edit_field_summary.php uses for its "Ikea"
              column), falling back to adjust_net_value ("Final B.V")
              only when no secondary-upload row matches that invoice
     CREDIT → every payment in invoice_payments (cash + cheque)
     DEBIT  → if a cheque later bounces (cheques.status='returned') or
              is sent back (status='sent_back'), the cheque amount is
              re-debited on the return date — cancelling out the
              original credit, exactly like a real bank statement
     DEBIT  → the Rs.250 cheque return charge itself, if one was raised
     CREDIT → credit notes (credit_notes table, linked to the invoice)

   All rows are sorted by date and a running balance is kept, same
   idea as the reference "Statement" PDF (Date | Description | Debit
   | Credit | Balance, with Total debits / Total credits / Closing
   balance at the foot).

   Opening balance: when a From/To date range is applied, the report
   carries forward an OPENING BALANCE — the running balance as of the
   moment right before the "From" date — instead of resetting the
   balance to 0 at the start of the visible window. This is done by
   computing the FULL running balance over the customer's entire
   history first, then slicing the visible window out of it, exactly
   like a real bank statement ("Balance brought forward").

   Two render modes in this one file:
     • normal page  → search a customer, pick an optional date range,
       view the ledger inline, "Print Statement" opens the print view
     • ?print=1     → clean, letterhead-style statement page (no
       header.php/footer.php chrome) formatted like the reference PDF
   ══════════════════════════════════════════════════════════════ */

ob_start();
include 'config.php';
if (function_exists('mysqli_report')) { mysqli_report(MYSQLI_REPORT_OFF); }
include_once 'auth.php';

/* ── DEFENSIVE TABLE CREATION — this page must work standalone even
   if nobody has visited the payment/cheque/secondary-upload pages yet ── */
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS invoice_payments (
  id                      INT AUTO_INCREMENT PRIMARY KEY,
  field_summary_id        INT           NOT NULL,
  field_summary_detail_id INT           NOT NULL,
  t_code                  VARCHAR(50)   NULL,
  invoice_num             VARCHAR(100)  NULL,
  payment_method          VARCHAR(20)   NOT NULL DEFAULT 'cash',
  payment_date            DATE          NULL,
  amount                  DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  amount_to_bank          DECIMAL(12,2) DEFAULT 0.00,
  reference_no            VARCHAR(100)  NULL,
  collected_by            VARCHAR(50)   NULL,
  cheque_mode             VARCHAR(50)   NULL,
  remarks                 TEXT          NULL,
  payment_source          VARCHAR(30)   NOT NULL DEFAULT 'invoice',
  delivery_person         VARCHAR(150)  NULL,
  employee_id             INT           NULL,
  sr_code                 VARCHAR(50)   NULL,
  created_at              TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
  is_reversed             TINYINT(1)    NOT NULL DEFAULT 0,
  reversed_at             DATETIME      NULL,
  INDEX idx_fs  (field_summary_id),
  INDEX idx_det (field_summary_detail_id),
  INDEX idx_inv (invoice_num),
  INDEX idx_tcode (t_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS invoice_payment_cheques (
  id                 INT AUTO_INCREMENT PRIMARY KEY,
  invoice_payment_id INT           NOT NULL,
  field_summary_id   INT           NULL,
  invoice_num        VARCHAR(100)  NULL,
  t_code             VARCHAR(50)   NULL,
  cheque_no          VARCHAR(100)  NOT NULL,
  cheque_date        DATE          NULL,
  amount             DECIMAL(12,2) DEFAULT 0.00,
  total_amount       DECIMAL(12,2) DEFAULT 0.00,
  bank_code          VARCHAR(50)   NULL,
  bank_name          VARCHAR(150)  NULL,
  branch_code        VARCHAR(50)   NULL,
  branch_name        VARCHAR(150)  NULL,
  status             VARCHAR(30)   DEFAULT 'pending',
  created_at         TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
  updated_at         TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  is_reversed        TINYINT(1)    NOT NULL DEFAULT 0,
  INDEX idx_pid (invoice_payment_id),
  INDEX idx_cno (cheque_no),
  INDEX idx_tcode (t_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS cheques (
  id                 INT           AUTO_INCREMENT PRIMARY KEY,
  invoice_payment_id INT           NOT NULL,
  field_summary_id   INT           NULL,
  t_code             VARCHAR(50)   NULL,
  cheque_no          VARCHAR(100)  NOT NULL,
  cheque_date        DATE          NULL,
  amount             DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  total_amount       DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  bank_code          VARCHAR(50)   NULL,
  bank_name          VARCHAR(150)  NULL,
  branch_code        VARCHAR(50)   NULL,
  branch_name        VARCHAR(150)  NULL,
  status             VARCHAR(30)   NOT NULL DEFAULT 'pending',
  created_at         TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
  updated_at         TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  received_date      DATE          NULL,
  due_date           DATE          NULL,
  bulk_deposit       VARCHAR(200)  NULL,
  acc_holder_name    VARCHAR(200)  NULL,
  bulk_flag          INT           NULL,
  UNIQUE KEY uq_cheque_bank_branch (cheque_no, bank_code, branch_code),
  INDEX idx_pid  (invoice_payment_id),
  INDEX idx_cno  (cheque_no),
  INDEX idx_bank (bank_code, branch_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS cheque_return_charges(
  id INT AUTO_INCREMENT PRIMARY KEY, cheque_id INT NOT NULL, cheque_no VARCHAR(100) NOT NULL,
  t_code VARCHAR(100) DEFAULT NULL, cheque_amount DECIMAL(15,2) DEFAULT 0,
  return_charge DECIMAL(15,2) NOT NULL DEFAULT 250.00, return_reason TEXT DEFAULT NULL,
  charged_at DATETIME DEFAULT CURRENT_TIMESTAMP, charged_by VARCHAR(100) DEFAULT 'system',
  INDEX idx_crc_cid(cheque_id), INDEX idx_crc_tcode(t_code), INDEX idx_crc_chqno(cheque_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `credit_notes` (
    `id`                       INT AUTO_INCREMENT PRIMARY KEY,
    `field_summary_detail_id`  INT NOT NULL,
    `amount`                   DECIMAL(12,2) NOT NULL,
    `reason`                   TEXT,
    `note_date`                DATE NOT NULL,
    `created_at`               DATETIME DEFAULT CURRENT_TIMESTAMP,
    `is_deleted`               TINYINT(1) NOT NULL DEFAULT 0,
    INDEX `idx_fsd_id` (`field_summary_detail_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS secondary_invoice_import_details (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    import_id INT(11) NOT NULL,
    sales_person_code VARCHAR(100) NULL,
    t_code VARCHAR(50) NULL,
    route_code VARCHAR(50) NULL,
    bill_no VARCHAR(100) NULL,
    bill_date DATE NULL,
    outlet_code VARCHAR(100) NULL,
    party_name VARCHAR(255) NULL,
    free_qty DECIMAL(12,2) DEFAULT 0,
    gross_sales DECIMAL(12,2) DEFAULT 0,
    scheme_disc DECIMAL(12,2) DEFAULT 0,
    rs_discount DECIMAL(12,2) DEFAULT 0,
    tot_disc DECIMAL(12,2) DEFAULT 0,
    total_discount DECIMAL(12,2) DEFAULT 0,
    bill_value DECIMAL(12,2) DEFAULT 0,
    good_returns_value DECIMAL(12,2) DEFAULT 0,
    damage_expiry_shortage_value DECIMAL(12,2) DEFAULT 0,
    final_bill_amount DECIMAL(12,2) DEFAULT 0,
    delivery_person VARCHAR(255) NULL,
    delivery_date DATE NULL,
    t_code_valid TINYINT(1) DEFAULT 0,
    route_valid TINYINT(1) DEFAULT 0,
    customer_name VARCHAR(255) NULL,
    route_name VARCHAR(255) NULL,
    status ENUM('pending','imported','failed') DEFAULT 'pending',
    error_message TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_import_id (import_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

/* crn_date_of_return may not exist on a fresh cheques table */
$cchk = mysqli_query($conn, "SHOW COLUMNS FROM cheques LIKE 'crn_date_of_return'");
if ($cchk && mysqli_num_rows($cchk) === 0) {
    mysqli_query($conn, "ALTER TABLE cheques ADD COLUMN crn_date_of_return DATE DEFAULT NULL");
}
$cchk2 = mysqli_query($conn, "SHOW COLUMNS FROM cheques LIKE 'return_reason'");
if ($cchk2 && mysqli_num_rows($cchk2) === 0) {
    mysqli_query($conn, "ALTER TABLE cheques ADD COLUMN return_reason TEXT DEFAULT NULL");
}

/* ── build the full ledger for one customer ── */

/* Walk one invoice's settlement events chronologically and return the
   date its running total last crossed up to (and stayed at) the full
   amount — or null if it's still open (never fully paid, or reopened
   by a later bounce and not yet re-settled). */
function invoice_close_date($events, $amount) {
    if (!$events) return null;
    usort($events, function ($a, $b) {
        $c = strcmp($a['date'], $b['date']);
        return $c !== 0 ? $c : ($a['seq'] <=> $b['seq']);
    });
    $running = 0.0; $closed_date = null;
    foreach ($events as $e) {
        $running += $e['amt'];
        if ($running >= $amount - 0.01) $closed_date = $e['date'];
        elseif ($closed_date !== null && $running < $amount - 0.01) $closed_date = null;
    }
    return $closed_date;
}

function days_between($from_date, $to_date) {
    $a = strtotime($from_date); $b = strtotime($to_date);
    if (!$a || !$b) return null;
    return (int) round(($b - $a) / 86400);
}

/*
   Builds the ledger.

   Every underlying query below fetches the customer's FULL
   transaction history — it is NOT restricted to $from/$to at the SQL
   level. The running balance is computed once over that full history
   (so it's always correct), and only THEN do we slice out the visible
   window:
     - 'opening'  = the balance as of the instant right before $from
                    (0 if $from is blank, i.e. "from the beginning")
     - 'rows'     = only the transactions that fall inside [$from,$to],
                    each still carrying its correct running 'balance'
     - 'closing'  = balance of the last visible row (or 'opening' if
                    the window has no rows in it)
   This mirrors a real bank statement's "Balance brought forward".

   Invoice DEBIT amount = the "Ikea value" — the final_bill_amount
   pulled live from secondary_invoice_import_details (matched by
   bill_no + delivery_date, status='imported'), the exact same live
   lookup edit_field_summary.php uses for its "Ikea" column — NOT
   field_summary_details.adjust_net_value. If a given invoice has no
   matching secondary-upload row, adjust_net_value is used as a
   fallback so the invoice doesn't just disappear from the ledger.

   AGING: an invoice's age is measured from its delivery_date up to
   the date its running settlement total (payments + credit notes,
   minus any bounced/sent-back cheque re-debits) last reaches and
   stays at the full invoice amount — or up to TODAY if it's still
   open. A bounced/sent-back cheque re-opens the invoice the same way
   it does in the balance itself, so a cheque bounce naturally makes
   the invoice "age" again until it's actually re-settled.

   The "Cheque Returned" / "Cheque Sent Back" row for that same
   invoice reuses this exact invoice-level aging (looked up by
   invoice_num): if the invoice was fully cleared again afterwards,
   the cheque-return row shows that same "cleared" aging; if the
   invoice is still open, it shows aging counted through to today —
   so the returned cheque keeps visibly aging until it's actually
   recovered.
*/
function build_customer_ledger($conn, $tcode, $from, $to) {
    $tcode_e = mysqli_real_escape_string($conn, $tcode);
    $rows = [];
    $seq  = 0;

    $cust = null;
    $cr = mysqli_query($conn, "SELECT * FROM customers WHERE t_code = '$tcode_e' LIMIT 1");
    if ($cr) $cust = mysqli_fetch_assoc($cr);

    /* ── AGING — per-invoice settlement events (always unrestricted by
       from/to, so the close date is correct even if it falls outside
       the viewed range). Keyed by invoice_num:
         + payment amount on its payment_date
         − bounced/sent-back cheque amount on its return date
         + credit note amount on its note_date
       Age = days between invoice date and the date the running total
       for that invoice first reaches (and stays at) the invoice amount. ── */
    $invoice_events = []; // invoice_num => [ ['date'=>Y-m-d,'amt'=>+/-,'seq'=>n], ... ]
    $ae_seq = 0;

    $paq = mysqli_query($conn, "SELECT invoice_num, payment_date, amount FROM invoice_payments WHERE t_code = '$tcode_e' AND invoice_num IS NOT NULL AND invoice_num <> ''");
    if ($paq) while ($pr = mysqli_fetch_assoc($paq)) {
        $invoice_events[$pr['invoice_num']][] = ['date' => $pr['payment_date'], 'amt' => floatval($pr['amount']), 'seq' => $ae_seq++];
    }

    $rvq = mysqli_query($conn, "
        SELECT ipc.invoice_num, ipc.amount, c.status AS cheque_status, c.crn_date_of_return, c.updated_at AS cheque_updated_at
        FROM invoice_payment_cheques ipc
        LEFT JOIN cheques c ON c.cheque_no = ipc.cheque_no AND c.bank_code = ipc.bank_code AND c.branch_code = ipc.branch_code
        WHERE ipc.t_code = '$tcode_e' AND ipc.invoice_num IS NOT NULL AND ipc.invoice_num <> ''");
    if ($rvq) while ($rr = mysqli_fetch_assoc($rvq)) {
        $st = $rr['cheque_status'] ?? '';
        if (!in_array($st, ['returned', 'sent_back'], true)) continue;
        $evt_date = $rr['crn_date_of_return'] ?: substr((string)$rr['cheque_updated_at'], 0, 10);
        if (!$evt_date) continue;
        $invoice_events[$rr['invoice_num']][] = ['date' => $evt_date, 'amt' => -floatval($rr['amount']), 'seq' => $ae_seq++];
    }

    /* Credit notes also settle an invoice's balance, same as a payment ── */
    $cnq = mysqli_query($conn, "
        SELECT fsd.invoice_num, cn.amount, cn.note_date
        FROM credit_notes cn
        JOIN field_summary_details fsd ON fsd.id = cn.field_summary_detail_id
        WHERE fsd.t_code = '$tcode_e' AND cn.is_deleted = 0");
    if ($cnq) while ($cr2 = mysqli_fetch_assoc($cnq)) {
        $invoice_events[$cr2['invoice_num']][] = ['date' => $cr2['note_date'], 'amt' => floatval($cr2['amount']), 'seq' => $ae_seq++];
    }

    /* invoice_num => ['age_days'=>int|null, 'age_open'=>bool, 'close_date'=>Y-m-d|null]
       filled in while building the invoice DEBIT rows below, then
       reused for that same invoice's "Cheque Returned"/"Sent Back" rows. */
    $invoice_aging = [];
    $today_str = date('Y-m-d');

    /* ── 1. DEBIT — invoices, FULL history (skip zero/blank-amount ones)
       Amount = Ikea value from secondary_invoice_import_details (live
       lookup, matched by bill_no + delivery_date, status='imported'),
       falling back to field_summary_details.adjust_net_value only when
       no secondary-upload row matches this invoice. ── */
    $q = mysqli_query($conn, "
        SELECT fsd.invoice_num, fsd.adjust_net_value, fs.delivery_date, fs.field_summary_code,
               sid.final_bill_amount AS ikea_value
        FROM field_summary_details fsd
        JOIN field_summary fs ON fs.id = fsd.field_summary_id
        LEFT JOIN secondary_invoice_import_details sid
               ON sid.bill_no = fsd.invoice_num
              AND sid.delivery_date = fs.delivery_date
              AND sid.status = 'imported'
        WHERE fsd.t_code = '$tcode_e'
        ORDER BY fs.delivery_date, fsd.id");
    if ($q) while ($r = mysqli_fetch_assoc($q)) {
        $amount = ($r['ikea_value'] !== null && floatval($r['ikea_value']) > 0)
            ? floatval($r['ikea_value'])
            : floatval($r['adjust_net_value']);
        if ($amount <= 0) continue; // still skip zero/blank-amount invoices

        $close_date = invoice_close_date($invoice_events[$r['invoice_num']] ?? [], $amount);
        if ($close_date) {
            $age_days = days_between($r['delivery_date'], $close_date);
            $age_open = false;
        } else {
            $age_days = days_between($r['delivery_date'], $today_str);
            $age_open = true;
        }

        /* remember this invoice's aging so a later "Cheque Returned"/
           "Cheque Sent Back" row for the same invoice can reuse it */
        $invoice_aging[$r['invoice_num']] = [
            'age_days'   => $age_days,
            'age_open'   => $age_open,
            'close_date' => $close_date,
        ];

        $rows[] = [
            'date'     => $r['delivery_date'],
            'type'     => 'invoice',
            'desc'     => 'Invoice ' . $r['invoice_num'] . ' — ' . $r['field_summary_code'],
            'debit'    => $amount,
            'credit'   => 0.0,
            'seq'      => $seq++,
            'age_days' => $age_days,
            'age_open' => $age_open,
        ];
    }

    /* ── 2. CREDIT — payments, FULL history (cash + cheque, incl. return/sent-back settlements) ── */
    $q = mysqli_query($conn, "SELECT ip.* FROM invoice_payments ip WHERE ip.t_code = '$tcode_e' ORDER BY ip.payment_date, ip.id");
    if ($q) while ($r = mysqli_fetch_assoc($q)) {
        $method = strtolower($r['payment_method']);
        $label  = $method === 'cheque' ? 'Cheque' : ucfirst($method);
        $bits   = ['Payment — ' . $label];
        if (!empty($r['invoice_num']))   $bits[] = 'Inv ' . $r['invoice_num'];
        if (!empty($r['reference_no']))  $bits[] = 'Ref ' . $r['reference_no'];
        switch ($r['payment_source']) {
            case 'return_cheque_settlement':   $bits[] = '(Cheque Return Settlement)'; break;
            case 'sentback_cheque_settlement': $bits[] = '(Sent-Back Settlement)';     break;
            case 'return_charge_settlement':   $bits[] = '(Return Charge Settlement)'; break;
            case 'credit_sale':
            case 'credit_sales':               $bits[] = '(Credit Sale)';              break;
        }
        $rows[] = [
            'date'     => $r['payment_date'],
            'type'     => 'payment',
            'desc'     => implode(' — ', $bits),
            'debit'    => 0.0,
            'credit'   => floatval($r['amount']),
            'seq'      => $seq++,
            'age_days' => null,
            'age_open' => null,
        ];
    }

    /* ── 3. DEBIT — cheques that bounced or were sent back, FULL history: re-debit the amount.
       Aging on this row is reused from that invoice's own aging (computed
       in section 1 above): if the invoice was fully re-cleared after the
       bounce, this row shows that "cleared" aging; if the invoice is
       still open, this row's aging counts through to today, so the
       returned cheque keeps visibly aging until it's actually recovered. ── */
    $q = mysqli_query($conn, "
        SELECT ipc.invoice_num, ipc.cheque_no, ipc.amount,
               c.status AS cheque_status, c.crn_date_of_return, c.updated_at AS cheque_updated_at, c.return_reason
        FROM invoice_payment_cheques ipc
        LEFT JOIN cheques c ON c.cheque_no = ipc.cheque_no
                            AND c.bank_code = ipc.bank_code
                            AND c.branch_code = ipc.branch_code
        WHERE ipc.t_code = '$tcode_e'");
    if ($q) while ($r = mysqli_fetch_assoc($q)) {
        $status = $r['cheque_status'] ?? '';
        if (!in_array($status, ['returned', 'sent_back'], true)) continue;

        $evt_date = $r['crn_date_of_return'] ?: substr((string)$r['cheque_updated_at'], 0, 10);
        if (!$evt_date) continue;

        $label  = $status === 'returned' ? 'Cheque Returned' : 'Cheque Sent Back';
        $desc   = $label . ' — ' . $r['cheque_no'];
        if (!empty($r['invoice_num']))   $desc .= ' (Inv ' . $r['invoice_num'] . ')';
        if (!empty($r['return_reason'])) $desc .= ' — ' . $r['return_reason'];

        $aging = $invoice_aging[$r['invoice_num']] ?? null;
        if ($aging) {
            $cr_age_days = $aging['age_days'];
            $cr_age_open = $aging['age_open'];
        } else {
            /* invoice row wasn't in the ledger (e.g. no matching
               field_summary_details row) — fall back to aging this
               return on its own: cleared if the invoice's settlement
               events reach this cheque's amount again after the
               return date, otherwise age through to today. */
            $fallback_close = invoice_close_date($invoice_events[$r['invoice_num']] ?? [], floatval($r['amount']));
            if ($fallback_close && $fallback_close >= $evt_date) {
                $cr_age_days = days_between($evt_date, $fallback_close);
                $cr_age_open = false;
            } else {
                $cr_age_days = days_between($evt_date, $today_str);
                $cr_age_open = true;
            }
        }

        $rows[] = [
            'date'     => $evt_date,
            'type'     => 'cheque_return',
            'desc'     => $desc,
            'debit'    => floatval($r['amount']),
            'credit'   => 0.0,
            'seq'      => $seq++,
            'age_days' => $cr_age_days,
            'age_open' => $cr_age_open,
        ];
    }

    /* ── 4. DEBIT — the return charge fee itself, FULL history ── */
    $q = mysqli_query($conn, "SELECT * FROM cheque_return_charges WHERE t_code = '$tcode_e'");
    if ($q) while ($r = mysqli_fetch_assoc($q)) {
        $evt_date = substr((string)$r['charged_at'], 0, 10);
        $rows[] = [
            'date'     => $evt_date,
            'type'     => 'return_charge',
            'desc'     => 'Cheque Return Charge — ' . $r['cheque_no'],
            'debit'    => floatval($r['return_charge']),
            'credit'   => 0.0,
            'seq'      => $seq++,
            'age_days' => null,
            'age_open' => null,
        ];
    }

    /* ── 5. CREDIT — credit notes, FULL history ── */
    $q = mysqli_query($conn, "
        SELECT cn.amount, cn.note_date, cn.reason, fsd.invoice_num
        FROM credit_notes cn
        JOIN field_summary_details fsd ON fsd.id = cn.field_summary_detail_id
        WHERE fsd.t_code = '$tcode_e' AND cn.is_deleted = 0");
    if ($q) while ($r = mysqli_fetch_assoc($q)) {
        $evt_date = $r['note_date'];
        $desc = 'Credit Note';
        if (!empty($r['invoice_num'])) $desc .= ' — Inv ' . $r['invoice_num'];
        if (!empty($r['reason']))      $desc .= ' — ' . $r['reason'];
        $rows[] = [
            'date'     => $evt_date,
            'type'     => 'credit_note',
            'desc'     => $desc,
            'debit'    => 0.0,
            'credit'   => floatval($r['amount']),
            'seq'      => $seq++,
            'age_days' => null,
            'age_open' => null,
        ];
    }

    /* ── sort FULL history chronologically, stable within the same date ── */
    usort($rows, function ($a, $b) {
        $c = strcmp($a['date'] ?? '', $b['date'] ?? '');
        return $c !== 0 ? $c : ($a['seq'] <=> $b['seq']);
    });

    /* ── running balance over the FULL history (Dr = customer owes us, Cr = credit / overpaid) ── */
    $bal = 0.0;
    foreach ($rows as &$r) {
        $bal += $r['debit'] - $r['credit'];
        $r['balance'] = $bal;
    }
    unset($r);

    /* ── slice out the visible window, carrying forward the opening balance ── */
    $opening = 0.0;
    $visible = [];
    foreach ($rows as $r) {
        if ($from !== '' && $r['date'] < $from) {
            // still before the window — keep pushing the opening balance forward
            $opening = $r['balance'];
            continue;
        }
        if ($to !== '' && $r['date'] > $to) continue; // past the window — ignore
        $visible[] = $r;
    }

    $total_debit = 0.0; $total_credit = 0.0;
    foreach ($visible as $r) {
        $total_debit  += $r['debit'];
        $total_credit += $r['credit'];
    }

    $closing = !empty($visible) ? $visible[count($visible) - 1]['balance'] : $opening;

    return [
        'customer'     => $cust,
        'rows'         => $visible,
        'opening'      => $opening,
        'total_debit'  => $total_debit,
        'total_credit' => $total_credit,
        'closing'      => $closing,
    ];
}

function bal_label($v) {
    return number_format(abs($v), 2) . ' ' . ($v >= 0 ? 'Dr' : 'Cr');
}

function age_label($age_days, $age_open) {
    if ($age_days === null) return '';
    return (string) $age_days;
}

/* ════════════════════════════════════════════════════════════
   AJAX ENDPOINTS  (interactive dashboard)
   ════════════════════════════════════════════════════════════ */
if (!empty($_GET['ajax']) && empty($_GET['print'])) {
    ob_clean();
    header('Content-Type: application/json');

    if (!function_exists('isLoggedIn') || !isLoggedIn()) {
        echo json_encode(['error' => 'Session expired — please refresh the page and log in again.']);
        exit;
    }

    if ($_GET['ajax'] === 'search_customers') {
        $q   = mysqli_real_escape_string($conn, trim($_GET['q'] ?? ''));
        $sql = "SELECT id, t_code, shop_name, route FROM customers WHERE active = 1";
        if ($q !== '') $sql .= " AND (t_code LIKE '%$q%' OR shop_name LIKE '%$q%')";
        $sql .= " ORDER BY shop_name ASC LIMIT 50";
        $res = mysqli_query($conn, $sql);
        $rows = [];
        if ($res) while ($r = mysqli_fetch_assoc($res)) {
            $rows[] = ['id' => $r['t_code'], 'text' => '[' . $r['t_code'] . '] ' . $r['shop_name'] . ($r['route'] ? ' — ' . $r['route'] : '')];
        }
        echo json_encode(['results' => $rows]);
        exit;
    }

    if ($_GET['ajax'] === 'ledger') {
        $tcode = trim($_GET['t_code'] ?? '');
        $from  = trim($_GET['from'] ?? '');
        $to    = trim($_GET['to'] ?? '');
        if ($tcode === '') { echo json_encode(['error' => 'Select a customer first']); exit; }
        if ($from !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = '';
        if ($to   !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   $to   = '';

        $data = build_customer_ledger($conn, $tcode, $from, $to);
        if (!$data['customer']) { echo json_encode(['error' => 'Customer not found']); exit; }
        echo json_encode(['success' => true, 'from' => $from, 'to' => $to] + $data);
        exit;
    }

    echo json_encode(['error' => 'Unknown ajax action']);
    exit;
}

/* ════════════════════════════════════════════════════════════
   PRINT VIEW  — standalone statement page, styled like the
   reference PDF (Date | Description | Debit | Credit | Balance)
   ════════════════════════════════════════════════════════════ */
if (!empty($_GET['print'])) {
    ob_clean();

    if (!function_exists('isLoggedIn') || !isLoggedIn()) { echo 'Session expired — please log in again.'; exit; }

    $tcode = trim($_GET['t_code'] ?? '');
    $from  = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : '';
    $to    = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to']   ?? '') ? $_GET['to']   : '';

    $data = build_customer_ledger($conn, $tcode, $from, $to);
    if (!$data['customer']) { echo 'Customer not found.'; exit; }
    $cust = $data['customer'];

    $company_info = ['company_name' => 'Yelo Group'];
    $ci = mysqli_query($conn, "SELECT company_name FROM companies LIMIT 1");
    if ($ci && $row = mysqli_fetch_assoc($ci)) $company_info['company_name'] = $row['company_name'];

    function pf($d) { if (!$d) return ''; $x = strtotime($d); return $x ? date('n/j/Y', $x) : $d; }
    $today = date('n/j/Y');
    ?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Statement — <?php echo htmlspecialchars($cust['shop_name']); ?></title>
<style>
*{box-sizing:border-box;}
body{font-family:Arial,Helvetica,sans-serif;color:#111;margin:0;padding:30px;background:#fff;font-size:13px;}
.stmt-wrap{max-width:900px;margin:0 auto;}
.stmt-title{background:#1f2430;color:#fff;padding:14px 20px;font-size:22px;font-weight:800;letter-spacing:.5px;}
.stmt-head{display:flex;justify-content:space-between;padding:20px 4px 10px;flex-wrap:wrap;gap:20px;}
.stmt-cust h2{margin:0 0 4px;font-size:20px;font-weight:800;}
.stmt-cust .range{font-size:13px;color:#374151;margin-top:6px;}
.stmt-cust .range strong{color:#111;}
.stmt-to{text-align:right;font-size:12px;color:#374151;line-height:1.5;}
.stmt-to .co-name{font-weight:800;font-size:14px;color:#111;}
table.ledger{width:100%;border-collapse:collapse;margin-top:14px;}
table.ledger th{background:#1f2430;color:#fff;font-size:11px;text-transform:uppercase;letter-spacing:.4px;padding:8px 10px;text-align:left;}
table.ledger th.num,table.ledger td.num{text-align:right;}
table.ledger td{padding:8px 10px;border-bottom:1px solid #e5e7eb;font-size:12.5px;vertical-align:top;}
table.ledger tr:nth-child(even) td{background:#f9fafb;}
table.ledger tr.opening-row td{background:#eef2ff !important;font-weight:800;font-style:italic;}
.bal-cell{font-weight:700;white-space:nowrap;}
.tot-row td{border-top:2px solid #1f2430;font-weight:800;background:#f3f4f6 !important;}
.foot{margin-top:14px;display:flex;justify-content:flex-end;}
.foot table{border-collapse:collapse;font-size:13px;}
.foot td{padding:5px 14px;}
.foot .lbl{color:#374151;}
.foot .val{font-weight:800;text-align:right;}
.foot .close td{border-top:2px solid #1f2430;padding-top:8px;}
.print-bar{max-width:900px;margin:0 auto 14px;display:flex;justify-content:flex-end;gap:8px;}
.print-btn{background:#dc2626;color:#fff;border:none;border-radius:8px;padding:9px 18px;font-size:13px;font-weight:700;cursor:pointer;}
.print-btn.alt{background:#374151;}
.legend{font-size:11px;color:#9ca3af;margin-top:14px;}
@media print{
  .print-bar{display:none;}
  body{padding:0;}
  .stmt-wrap{max-width:100%;}
}
</style>
</head>
<body>

<div class="print-bar">
    <button class="print-btn alt" onclick="window.close()">Close</button>
    <button class="print-btn" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button>
</div>

<div class="stmt-wrap">
    <div class="stmt-title">Statement</div>

    <div class="stmt-head">
        <div class="stmt-cust">
            <h2><?php echo htmlspecialchars($cust['shop_name']); ?></h2>
            <div style="color:#6b7280;">T-Code: <?php echo htmlspecialchars($cust['t_code']); ?><?php echo $cust['route'] ? ' &nbsp;•&nbsp; Route: '.htmlspecialchars($cust['route']) : ''; ?></div>
            <div class="range">From <strong><?php echo $from ? pf($from) : '—'; ?></strong> To <strong><?php echo $to ? pf($to) : $today; ?></strong></div>
        </div>
        <div class="stmt-to">
            <div class="co-name"><?php echo htmlspecialchars($company_info['company_name']); ?></div>
            <?php if (!empty($cust['address'])): ?><div><?php echo nl2br(htmlspecialchars($cust['address'])); ?></div><?php endif; ?>
            <?php if (!empty($cust['telephone_number'])): ?><div><?php echo htmlspecialchars($cust['telephone_number']); ?></div><?php endif; ?>
        </div>
    </div>

    <table class="ledger">
        <thead><tr>
            <th style="width:90px;">Date</th>
            <th>Description</th>
            <th class="num" style="width:110px;">Debit</th>
            <th class="num" style="width:110px;">Credit</th>
            <th class="num" style="width:70px;">Age</th>
            <th class="num" style="width:130px;">Balance</th>
        </tr></thead>
        <tbody>
        <?php if ($from): ?>
            <tr class="opening-row">
                <td><?php echo pf($from); ?></td>
                <td>Balance Brought Forward (Opening Balance)</td>
                <td class="num">—</td>
                <td class="num">—</td>
                <td class="num">—</td>
                <td class="num bal-cell"><?php echo bal_label($data['opening']); ?></td>
            </tr>
        <?php endif; ?>
        <?php if (empty($data['rows'])): ?>
            <tr><td colspan="6" style="text-align:center;color:#9ca3af;padding:30px;"><?php echo $from ? 'No transactions in this period — balance unchanged.' : 'No transactions for this period.'; ?></td></tr>
        <?php else: foreach ($data['rows'] as $r): ?>
            <tr>
                <td><?php echo pf($r['date']); ?></td>
                <td><?php echo htmlspecialchars($r['desc']); ?></td>
                <td class="num"><?php echo $r['debit']  > 0 ? number_format($r['debit'], 2)  : '—'; ?></td>
                <td class="num"><?php echo $r['credit'] > 0 ? number_format($r['credit'], 2) : '—'; ?></td>
                <td class="num" style="<?php echo !empty($r['age_open']) ? 'color:#b45309;font-weight:700;' : ''; ?>"><?php echo age_label($r['age_days'] ?? null, $r['age_open'] ?? false); ?></td>
                <td class="num bal-cell"><?php echo bal_label($r['balance']); ?></td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>

    <div class="foot">
        <table>
            <?php if ($from): ?>
            <tr><td class="lbl">Opening balance</td><td class="val"><?php echo bal_label($data['opening']); ?></td></tr>
            <?php endif; ?>
            <tr><td class="lbl">Total debits</td><td class="val"><?php echo number_format($data['total_debit'], 2); ?> Dr</td></tr>
            <tr><td class="lbl">Total credits</td><td class="val"><?php echo number_format($data['total_credit'], 2); ?> Cr</td></tr>
            <tr class="close"><td class="lbl">Closing balance</td><td class="val"><?php echo bal_label($data['closing']); ?></td></tr>
        </table>
    </div>

    <div class="legend">Dr = amount owed by customer &nbsp;•&nbsp; Cr = credit balance / overpaid &nbsp;•&nbsp; Printed <?php echo $today; ?></div>
</div>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
</body>
</html>
<?php
    exit;
}

/* ════════════════════════════════════════════════════════════
   NORMAL PAGE — search + interactive ledger dashboard
   ════════════════════════════════════════════════════════════ */
include 'header.php';
?>

<style>
.cl-wrap        { max-width:1300px; }
.cl-card        { background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:20px; margin-bottom:20px; }
.cl-filters     { display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end; }
.cl-field       { display:flex; flex-direction:column; gap:5px; }
.cl-field label { font-size:11px; font-weight:700; color:#6b7280; text-transform:uppercase; letter-spacing:.4px; }
.cl-field input, .cl-field select { border:1px solid #d1d5db; border-radius:8px; padding:8px 10px; font-size:13px; font-family:inherit; }
.cl-cust-pick   { min-width:340px; flex:1; }
.cfs-btn        { border:none; border-radius:8px; padding:9px 18px; font-size:13px; font-weight:700; cursor:pointer; font-family:inherit; display:inline-flex; align-items:center; gap:6px; }
.cfs-btn:disabled{ opacity:.45; cursor:not-allowed; }
.btn-view       { background:#dc2626; color:#fff; }
.btn-print      { background:#374151; color:#fff; }

.cl-kpis        { display:grid; grid-template-columns:repeat(4,1fr); gap:14px; margin-bottom:18px; }
.cl-kpi         { background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:16px 18px; position:relative; overflow:hidden; }
.cl-kpi::before { content:''; position:absolute; left:0; top:0; bottom:0; width:4px; background:var(--acc,#dc2626); }
.cl-kpi .lbl    { font-size:11px; font-weight:700; color:#6b7280; text-transform:uppercase; letter-spacing:.5px; }
.cl-kpi .val    { font-size:22px; font-weight:900; color:#111; margin-top:2px; }

.cl-table       { width:100%; border-collapse:collapse; font-size:12.5px; }
.cl-table th    { background:#f9fafb; text-align:left; padding:9px 12px; font-size:11px; font-weight:700; color:#6b7280; text-transform:uppercase; letter-spacing:.4px; border-bottom:1px solid #e5e7eb; white-space:nowrap; }
.cl-table td    { padding:9px 12px; border-bottom:1px solid #f3f4f6; }
.cl-table .num  { text-align:right; white-space:nowrap; }
.cl-table tr.t-invoice td.desc       { color:#7f1d1d; }
.cl-table tr.t-payment td.desc       { color:#166534; }
.cl-table tr.t-cheque_return td.desc,
.cl-table tr.t-return_charge td.desc { color:#b45309; }
.cl-table tr.t-credit_note td.desc   { color:#0e7490; }
.cl-table tr.opening-row td          { background:#eef2ff; font-weight:800; font-style:italic; }
.bal-dr { color:#dc2626; font-weight:800; }
.bal-cr { color:#16a34a; font-weight:800; }
.cl-empty { text-align:center; padding:50px 20px; color:#9ca3af; }
</style>

<link href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css" rel="stylesheet"/>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js"></script>

<div class="cl-wrap">

<div class="page-header">
    <h2 class="page-title"><i class="fa-solid fa-book" style="color:#dc2626;"></i> Customer Ledger</h2>
    <p class="page-subtitle">Full statement for one customer — invoices (debit, Ikea value), cash &amp; cheque payments and credit notes (credit), with bounced/sent-back cheques re-debited on their return date. When a From date is set, the balance carries forward from an Opening Balance instead of resetting to zero.</p>
</div>

<div class="cl-card">
    <div class="cl-filters">
        <div class="cl-field cl-cust-pick">
            <label>Customer</label>
            <select id="custPicker" style="width:100%;"></select>
        </div>
        <div class="cl-field">
            <label>From</label>
            <input type="date" id="fromDate">
        </div>
        <div class="cl-field">
            <label>To</label>
            <input type="date" id="toDate">
        </div>
        <button class="cfs-btn btn-view" id="btnView" onclick="viewLedger()" disabled><i class="fa-solid fa-magnifying-glass"></i> View Ledger</button>
        <button class="cfs-btn btn-print" id="btnPrint" onclick="printLedger()" disabled><i class="fa-solid fa-print"></i> Print Statement</button>
    </div>
</div>

<div id="ledgerResults" style="display:none;">
    <div class="cl-kpis">
        <div class="cl-kpi" id="kpiOpeningWrap" style="--acc:#6366f1;display:none;"><div class="lbl">Opening Balance</div><div class="val" id="kpiOpening">--</div></div>
        <div class="cl-kpi" style="--acc:#dc2626;"><div class="lbl">Total Debits (Invoices)</div><div class="val" id="kpiDebit">--</div></div>
        <div class="cl-kpi" style="--acc:#16a34a;"><div class="lbl">Total Credits (Payments)</div><div class="val" id="kpiCredit">--</div></div>
        <div class="cl-kpi" style="--acc:#111827;"><div class="lbl">Closing Balance</div><div class="val" id="kpiClosing">--</div></div>
    </div>
    <div class="cl-card" style="padding:0;overflow:hidden;">
        <div class="table-responsive">
            <table class="cl-table">
                <thead><tr>
                    <th>Date</th><th>Description</th>
                    <th class="num">Debit</th><th class="num">Credit</th><th class="num">Age</th><th class="num">Balance</th>
                </tr></thead>
                <tbody id="ledgerBody"></tbody>
            </table>
        </div>
    </div>
</div>

<div id="ledgerEmpty" class="cl-empty">
    <i class="fa-solid fa-user-magnifying-glass" style="font-size:40px;color:#d1d5db;display:block;margin-bottom:10px;"></i>
    Search for a customer above, then click "View Ledger".
</div>

</div><!-- /cl-wrap -->

<script>
let currentTcode = '';

function fmtNum(n) { return parseFloat(n||0).toLocaleString('en',{minimumFractionDigits:2,maximumFractionDigits:2}); }
function fmtDate(d) { if(!d) return '—'; const x=new Date(d); return isNaN(x)?d:x.toLocaleDateString('en-GB',{day:'2-digit',month:'short',year:'numeric'}); }
function escH(s) { if(s===null||s===undefined) return ''; return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function balHtml(v) { const c = v>=0 ? 'bal-dr':'bal-cr'; return `<span class="${c}">${fmtNum(Math.abs(v))} ${v>=0?'Dr':'Cr'}</span>`; }

function fetchJSON(url) {
    return fetch(url).then(r => r.text()).then(raw => {
        if (!raw || !raw.trim()) throw new Error('Server returned an empty response.');
        try { return JSON.parse(raw); }
        catch(e){ throw new Error('Server returned non-JSON: ' + raw.replace(/<[^>]*>/g,' ').substring(0,200)); }
    });
}

$(function(){
    $('#custPicker').select2({
        placeholder: 'Search customer (T-Code or Shop Name)…',
        allowClear: true,
        ajax: {
            url: '?ajax=search_customers',
            dataType: 'json',
            delay: 250,
            data: params => ({ q: params.term || '' }),
            processResults: data => ({ results: data.results || [] })
        },
        minimumInputLength: 0
    }).on('change', function(){
        currentTcode = $(this).val() || '';
        document.getElementById('btnView').disabled = !currentTcode;
        document.getElementById('btnPrint').disabled = true;
    });
});

function viewLedger() {
    if (!currentTcode) return;
    const from = document.getElementById('fromDate').value;
    const to   = document.getElementById('toDate').value;

    const btn = document.getElementById('btnView');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Loading…';

    let url = '?ajax=ledger&t_code=' + encodeURIComponent(currentTcode);
    if (from) url += '&from=' + from;
    if (to)   url += '&to=' + to;

    fetchJSON(url)
        .then(data => {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-magnifying-glass"></i> View Ledger';
            if (data.error) { alert(data.error); return; }
            renderLedger(data);
            document.getElementById('btnPrint').disabled = false;
        })
        .catch(err => {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-magnifying-glass"></i> View Ledger';
            alert('Network error: ' + err.message);
        });
}

function renderLedger(data) {
    document.getElementById('ledgerEmpty').style.display = 'none';
    document.getElementById('ledgerResults').style.display = '';

    const hasFrom = !!data.from;

    if (hasFrom) {
        document.getElementById('kpiOpeningWrap').style.display = '';
        document.getElementById('kpiOpening').innerHTML = balHtml(data.opening);
    } else {
        document.getElementById('kpiOpeningWrap').style.display = 'none';
    }

    document.getElementById('kpiDebit').textContent   = 'Rs. ' + fmtNum(data.total_debit);
    document.getElementById('kpiCredit').textContent  = 'Rs. ' + fmtNum(data.total_credit);
    document.getElementById('kpiClosing').innerHTML   = balHtml(data.closing);

    const body = document.getElementById('ledgerBody');
    let html = '';

    if (hasFrom) {
        html += `<tr class="opening-row">
            <td>${fmtDate(data.from)}</td>
            <td>Balance Brought Forward (Opening Balance)</td>
            <td class="num">—</td>
            <td class="num">—</td>
            <td class="num">—</td>
            <td class="num">${balHtml(data.opening)}</td>
        </tr>`;
    }

    if (!data.rows || !data.rows.length) {
        html += `<tr><td colspan="6" style="text-align:center;color:#9ca3af;padding:30px;">${hasFrom ? 'No transactions in this period — balance unchanged.' : 'No transactions for this period.'}</td></tr>`;
        body.innerHTML = html;
        return;
    }

    data.rows.forEach(r => {
        const ageTxt = (r.age_days === null || r.age_days === undefined) ? '' : r.age_days;
        const ageCls = r.age_open ? 'style="color:#b45309;font-weight:700;"' : '';
        html += `<tr class="t-${r.type}">
            <td>${fmtDate(r.date)}</td>
            <td class="desc">${escH(r.desc)}</td>
            <td class="num">${r.debit  > 0 ? fmtNum(r.debit)  : '—'}</td>
            <td class="num">${r.credit > 0 ? fmtNum(r.credit) : '—'}</td>
            <td class="num" ${ageCls}>${ageTxt}</td>
            <td class="num">${balHtml(r.balance)}</td>
        </tr>`;
    });
    body.innerHTML = html;
}

function printLedger() {
    if (!currentTcode) return;
    const from = document.getElementById('fromDate').value;
    const to   = document.getElementById('toDate').value;
    let url = '?print=1&t_code=' + encodeURIComponent(currentTcode);
    if (from) url += '&from=' + from;
    if (to)   url += '&to=' + to;
    window.open(url, '_blank');
}
</script>

<?php include 'footer.php'; ?>