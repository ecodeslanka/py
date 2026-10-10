<?php
include 'config.php';

$id = intval($_GET['id'] ?? 0);
if (!$id) { echo '<p style="font-family:sans-serif;padding:40px;color:#dc2626">No ID provided.</p>'; exit; }

$row = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT a.*,
            ccb.leaf_no_start, ccb.leaf_no_end, ccb.customer_name AS book_name,
            cba.account_no AS bank_account_no_full, cba.account_name,
            COALESCE(NULLIF(b.bank_name,''), cba.bank_code, '') AS bank_name_resolved
     FROM dl_cheque_ack_new a
     LEFT JOIN customer_claim_cheque_books ccb ON ccb.id = a.cheque_book_id
     LEFT JOIN company_bank_accounts cba ON cba.id = a.bank_account_id
     LEFT JOIN banks b ON b.bank_code = cba.bank_code
     WHERE a.id = $id LIMIT 1"));

if (!$row) { echo '<p style="font-family:sans-serif;padding:40px;color:#dc2626">Record not found.</p>'; exit; }

// Number to words helper
function numberToWords($amount) {
    $amount  = floatval($amount);
    $integer = intval($amount);
    $decimal = round(($amount - $integer) * 100);

    $ones   = ['','One','Two','Three','Four','Five','Six','Seven','Eight','Nine',
               'Ten','Eleven','Twelve','Thirteen','Fourteen','Fifteen','Sixteen',
               'Seventeen','Eighteen','Nineteen'];
    $tens   = ['','','Twenty','Thirty','Forty','Fifty','Sixty','Seventy','Eighty','Ninety'];
    $scales = ['','Thousand','Million','Billion'];

    function convertBelow1000($n, $ones, $tens) {
        $result = '';
        if ($n >= 100) { $result .= $ones[intval($n/100)] . ' Hundred '; $n %= 100; }
        if ($n >= 20)  { $result .= $tens[intval($n/10)] . ' '; $n %= 10; }
        if ($n > 0)    { $result .= $ones[$n] . ' '; }
        return $result;
    }

    if ($integer === 0) { $words = 'Zero'; }
    else {
        $words = ''; $i = 0;
        while ($integer > 0) {
            $chunk = $integer % 1000;
            if ($chunk > 0) {
                $w = convertBelow1000($chunk, $ones, $tens);
                $words = $w . ($scales[$i] ? $scales[$i] . ' ' : '') . $words;
            }
            $integer = intval($integer / 1000); $i++;
        }
        $words = trim($words);
    }
    if ($decimal > 0) $words .= ' and ' . $decimal . '/100';
    return $words . ' Only';
}

$net    = floatval($row['actual_amount']);
$vat    = floatval($row['vat_amount']);
$total  = floatval($row['total_amount']);
$amtWords = numberToWords($total);
$isCust  = ($row['source_type'] === 'customer');
$printDate = date('d F Y');
$chequeNo  = $row['cheque_no'] ?: '—';
$chequeDate= $row['cheque_date'] ? date('d M Y', strtotime($row['cheque_date'])) : '—';
$commonDate= $row['common_date'] ? date('d M Y', strtotime($row['common_date'])) : null;

// Fetch company name from config if available
$companyRow = mysqli_fetch_assoc(mysqli_query($conn, "SELECT company_name FROM companies LIMIT 1"));
$companyName = $companyRow['company_name'] ?? 'Company Name';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Cheque Acknowledgment — <?php echo htmlspecialchars($row['tax_invoice_no'] ?: 'ACK-'.$id); ?></title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Times New Roman',Times,serif;background:#fff;color:#111;font-size:13pt}
@page{size:A4;margin:18mm 16mm}
@media print{body{margin:0}.no-print{display:none!important}}

.ack-page{max-width:210mm;margin:0 auto;padding:12mm 14mm;background:#fff;min-height:297mm;position:relative}
.copy-watermark{position:absolute;top:50%;left:50%;transform:translate(-50%,-50%) rotate(-35deg);font-size:80pt;font-weight:800;color:rgba(30,58,95,.04);pointer-events:none;white-space:nowrap;letter-spacing:6px;user-select:none;z-index:0}
.content{position:relative;z-index:1}

/* Header */
.ack-header{border-bottom:3px double #1e3a5f;padding-bottom:10px;margin-bottom:18px;display:flex;justify-content:space-between;align-items:flex-start}
.co-name{font-size:18pt;font-weight:700;color:#1e3a5f;letter-spacing:.5px}
.co-sub{font-size:9.5pt;color:#64748b;margin-top:2px}
.ack-label-wrap{text-align:right}
.ack-label{font-size:15pt;font-weight:700;color:#1e3a5f;text-transform:uppercase;letter-spacing:2px;border:2px solid #1e3a5f;padding:4px 14px;display:inline-block}
.ack-ref{font-size:9pt;color:#64748b;margin-top:5px}

/* Info table */
.info-table{width:100%;border-collapse:collapse;margin-bottom:18px}
.info-table td{padding:5px 8px;font-size:11pt;vertical-align:top}
.info-table td.lbl{font-weight:700;color:#374151;width:38%;white-space:nowrap}
.info-table td.colon{width:4%;text-align:center;color:#94a3b8}
.info-table td.val{color:#111;font-weight:600}

/* Amount table */
.amt-table{width:100%;border-collapse:collapse;margin-bottom:14px;border:1.5px solid #1e3a5f}
.amt-table thead th{background:#1e3a5f;color:#fff;padding:8px 12px;text-align:left;font-size:11pt;font-weight:700;letter-spacing:.3px}
.amt-table thead th:last-child{text-align:right}
.amt-table tbody td{padding:8px 12px;font-size:11.5pt;border-bottom:1px solid #e2e8f0;vertical-align:top}
.amt-table tbody td:last-child{text-align:right;font-weight:700}
.amt-table tfoot td{padding:9px 12px;font-weight:700;background:#f0f9ff;border-top:2px solid #1e3a5f;font-size:12pt}
.amt-table tfoot td:last-child{text-align:right;color:#1e3a5f;font-size:13pt;font-weight:800}

/* Cheque box */
.cheque-box{border:2px solid #7c3aed;border-radius:8px;padding:14px 18px;margin-bottom:16px;background:#faf5ff;position:relative}
.cheque-box-title{font-size:10pt;font-weight:800;color:#7c3aed;text-transform:uppercase;letter-spacing:.8px;margin-bottom:10px;display:flex;align-items:center;gap:6px}
.cheque-box-title::before{content:'';display:inline-block;width:16px;height:2px;background:#7c3aed}
.cheque-grid{display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px}
.cheque-item{display:flex;flex-direction:column;gap:2px}
.cheque-lbl{font-size:8.5pt;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.5px}
.cheque-val{font-size:13pt;font-weight:800;color:#312e81;font-family:'Courier New',monospace}
.cheque-val.date{font-family:'Times New Roman',serif;color:#1e3a5f;font-size:11.5pt}
.cheque-val.bank{font-family:'Times New Roman',serif;color:#374151;font-size:11pt;font-weight:700}

/* Amount in words */
.words-box{background:#f0fdf4;border:1.5px solid #86efac;border-radius:6px;padding:10px 14px;margin-bottom:14px}
.words-lbl{font-size:9pt;font-weight:700;color:#16a34a;text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px}
.words-val{font-size:11pt;font-weight:700;color:#0f172a;font-style:italic}

/* Common date */
.common-date-bar{background:#eff6ff;border:1.5px solid #bfdbfe;border-radius:6px;padding:8px 14px;margin-bottom:14px;display:flex;align-items:center;gap:14px}
.common-date-lbl{font-size:9pt;font-weight:700;color:#1e40af}
.common-date-val{font-size:11.5pt;font-weight:800;color:#1e40af;font-family:'Courier New',monospace}

/* Signature */
.sig-section{margin-top:28px;display:grid;grid-template-columns:1fr 1fr 1fr;gap:20px}
.sig-item{display:flex;flex-direction:column;align-items:center;gap:4px}
.sig-line{width:100%;height:1px;background:#374151;margin-top:30px;margin-bottom:5px}
.sig-lbl{font-size:9pt;color:#374151;font-weight:700;text-align:center}
.sig-sub{font-size:8pt;color:#94a3b8;text-align:center}

/* Footer */
.ack-footer{position:absolute;bottom:12mm;left:14mm;right:14mm;border-top:1px solid #e2e8f0;padding-top:6px;display:flex;justify-content:space-between;font-size:8pt;color:#94a3b8}

/* Print date */
.print-date-row{text-align:right;font-size:9pt;color:#64748b;margin-bottom:14px;font-style:italic}

.no-print{margin-bottom:18px;display:flex;gap:10px}
.btn-p{display:inline-flex;align-items:center;gap:6px;padding:9px 20px;border:none;border-radius:7px;font-size:13px;font-weight:700;cursor:pointer;font-family:sans-serif;text-decoration:none}
.btn-navy{background:#1e3a5f;color:#fff}.btn-light{background:#f3f4f6;color:#374151;border:1px solid #e5e7eb}
</style>
</head>
<body>

<!-- Print controls (hidden on print) -->
<div class="no-print" style="padding:16px 20px;background:#f8fafc;border-bottom:1px solid #e2e8f0">
    <button class="btn-p btn-navy" onclick="window.print()">&#128424; Print</button>
    <a class="btn-p btn-light" href="dl_cheque_ack_new.php">&#8592; Back</a>
</div>

<div class="ack-page">
    <div class="copy-watermark">ACKNOWLEDGMENT</div>
    <div class="content">

        <!-- Header -->
        <div class="ack-header">
            <div>
                <div class="co-name"><?php echo htmlspecialchars($companyName); ?></div>
                <div class="co-sub">Cheque Payment Acknowledgment</div>
            </div>
            <div class="ack-label-wrap">
                <div class="ack-label">Acknowledgment</div>
                <div class="ack-ref">Ref: ACK-<?php echo str_pad($id, 6, '0', STR_PAD_LEFT); ?></div>
            </div>
        </div>

        <!-- Print date -->
        <div class="print-date-row">Printed: <?php echo $printDate; ?></div>

        <!-- Recipient Info -->
        <table class="info-table">
            <tr>
                <td class="lbl">Recipient Type</td>
                <td class="colon">:</td>
                <td class="val"><?php echo $isCust ? 'Customer' : 'Employee'; ?></td>
                <td class="lbl">Name</td>
                <td class="colon">:</td>
                <td class="val"><?php echo htmlspecialchars($row['ref_name'] ?? '—'); ?></td>
            </tr>
            <tr>
                <td class="lbl">Code / ID</td>
                <td class="colon">:</td>
                <td class="val"><?php echo htmlspecialchars($row['ref_code'] ?? '—'); ?></td>
                <td class="lbl">Entity</td>
                <td class="colon">:</td>
                <td class="val"><?php echo htmlspecialchars($row['entity'] ?? '—'); ?></td>
            </tr>
            <?php if (!empty($row['tax_invoice_no'])): ?>
            <tr>
                <td class="lbl">Tax Invoice No.</td>
                <td class="colon">:</td>
                <td class="val" style="font-family:'Courier New';font-weight:800;color:#1e3a5f"><?php echo htmlspecialchars($row['tax_invoice_no']); ?></td>
                <td class="lbl">Invoice Date</td>
                <td class="colon">:</td>
                <td class="val"><?php echo $row['invoice_date'] ? date('d M Y', strtotime($row['invoice_date'])) : '—'; ?></td>
            </tr>
            <?php endif; ?>
            <?php if (!empty($row['claim_type'])): ?>
            <tr>
                <td class="lbl">Claim Type</td>
                <td class="colon">:</td>
                <td class="val" colspan="3"><?php echo htmlspecialchars($row['claim_type']); ?></td>
            </tr>
            <?php endif; ?>
            <?php if (!empty($row['claim_description'])): ?>
            <tr>
                <td class="lbl">Description</td>
                <td class="colon">:</td>
                <td class="val" colspan="3"><?php echo htmlspecialchars($row['claim_description']); ?></td>
            </tr>
            <?php endif; ?>
        </table>

        <!-- Amount Table -->
        <table class="amt-table">
            <thead>
                <tr>
                    <th>Description</th>
                    <th style="text-align:right">Amount (LKR)</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>Net Amount (Excl. VAT)</td>
                    <td><?php echo number_format($net, 2); ?></td>
                </tr>
                <tr>
                    <td>VAT @ 18%</td>
                    <td><?php echo number_format($vat, 2); ?></td>
                </tr>
            </tbody>
            <tfoot>
                <tr>
                    <td>Total Amount (Incl. VAT)</td>
                    <td><?php echo number_format($total, 2); ?></td>
                </tr>
            </tfoot>
        </table>

        <!-- Amount in words -->
        <div class="words-box">
            <div class="words-lbl">Amount in Words</div>
            <div class="words-val">LKR <?php echo $amtWords; ?></div>
        </div>

        <!-- Common Date (if set) -->
        <?php if ($commonDate): ?>
        <div class="common-date-bar">
            <div class="common-date-lbl">&#128197; Common / Batch Banking Date :</div>
            <div class="common-date-val"><?php echo $commonDate; ?></div>
        </div>
        <?php endif; ?>

        <!-- Cheque Details -->
        <div class="cheque-box">
            <div class="cheque-box-title">Cheque Details</div>
            <div class="cheque-grid">
                <div class="cheque-item">
                    <div class="cheque-lbl">Cheque No.</div>
                    <div class="cheque-val"><?php echo htmlspecialchars($chequeNo); ?></div>
                </div>
                <div class="cheque-item">
                    <div class="cheque-lbl">Cheque Date</div>
                    <div class="cheque-val date"><?php echo $chequeDate; ?></div>
                </div>
                <div class="cheque-item">
                    <div class="cheque-lbl">Book Range</div>
                    <div class="cheque-val" style="font-size:11pt">
                        <?php echo ($row['leaf_no_start'] && $row['leaf_no_end'])
                            ? htmlspecialchars($row['leaf_no_start'] . '–' . $row['leaf_no_end'])
                            : '—'; ?>
                    </div>
                </div>
                <div class="cheque-item">
                    <div class="cheque-lbl">Bank Account No.</div>
                    <div class="cheque-val bank"><?php echo htmlspecialchars($row['bank_account_no'] ?: ($row['bank_account_no_full'] ?? '—')); ?></div>
                </div>
                <div class="cheque-item">
                    <div class="cheque-lbl">Bank Name</div>
                    <div class="cheque-val bank"><?php echo htmlspecialchars($row['bank_name'] ?: ($row['bank_name_resolved'] ?? '—')); ?></div>
                </div>
                <div class="cheque-item">
                    <div class="cheque-lbl">Book Name</div>
                    <div class="cheque-val bank" style="font-size:10pt"><?php echo htmlspecialchars($row['book_name'] ?? '—'); ?></div>
                </div>
            </div>
        </div>

        <!-- Signature section -->
        <div class="sig-section">
            <div class="sig-item">
                <div class="sig-line"></div>
                <div class="sig-lbl">Prepared By</div>
                <div class="sig-sub">Accounts Department</div>
            </div>
            <div class="sig-item">
                <div class="sig-line"></div>
                <div class="sig-lbl">Authorized By</div>
                <div class="sig-sub">Finance Manager</div>
            </div>
            <div class="sig-item">
                <div class="sig-line"></div>
                <div class="sig-lbl">Received By</div>
                <div class="sig-sub"><?php echo htmlspecialchars($row['ref_name'] ?? '—'); ?></div>
            </div>
        </div>

        <!-- Footer -->
        <div class="ack-footer">
            <span><?php echo htmlspecialchars($companyName); ?> · Cheque Acknowledgment System</span>
            <span>ACK-<?php echo str_pad($id, 6, '0', STR_PAD_LEFT); ?> · Printed: <?php echo $printDate; ?></span>
        </div>
    </div>
</div>

<script>
// Auto-print on load (optional — comment out if not desired)
// window.addEventListener('load', () => window.print());
</script>
</body>
</html>
