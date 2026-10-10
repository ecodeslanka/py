<?php
include 'config.php';

/* ═══════════════════════════════════════════════════════
   PAYMENT INVOICE PRINT PAGE
   Fetches payment invoice + all linked invoices
   Aggregates items (sums quantities for duplicates)
   Renders professional A4 format for printing
═══════════════════════════════════════════════════════ */

$pi_id = intval($_GET['pi'] ?? 0);

if (!$pi_id) {
    die('<h2>Error: No payment invoice specified</h2>');
}

/* ── Fetch payment invoice ── */
$pi_row = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT pi.*, 
            c.name AS cat_name
     FROM ushop_payment_invoices pi
     LEFT JOIN ushop_letter_categories c ON c.id = pi.category_id
     WHERE pi.id=$pi_id"
));

if (!$pi_row) {
    die('<h2>Error: Payment invoice not found</h2>');
}

/* ── Get company settings (header, footer, signature, name) ── */
function get_company_setting($conn, $key) {
    $k = mysqli_real_escape_string($conn, $key);
    $res = mysqli_query($conn, "SELECT `value` FROM company_settings WHERE `key`='$k' LIMIT 1");
    return $res ? (mysqli_fetch_assoc($res)['value'] ?? '') : '';
}

$company_name = get_company_setting($conn, 'letterhead_company');
$header_img   = get_company_setting($conn, 'letterhead_header');
$footer_img   = get_company_setting($conn, 'letterhead_footer');
$esign_img    = get_company_setting($conn, 'letterhead_esign');

/* ── Parse invoice IDs ── */
$invoice_ids = [];
if ($pi_row['invoice_ids']) {
    $invoice_ids = array_filter(array_map('intval', explode(',', $pi_row['invoice_ids'])));
}

if (empty($invoice_ids)) {
    die('<h2>Error: No invoices linked to this payment invoice</h2>');
}

/* ── Fetch all invoices ── */
$ids_sql = implode(',', $invoice_ids);
$invs_res = mysqli_query($conn, "
    SELECT id, invoice_date, doc_no, unique_inv_no,
           customer_name, customer_code
    FROM ushop_invoices
    WHERE id IN ($ids_sql)
    ORDER BY FIELD(id, $ids_sql)
");

$invoices = [];
while ($r = mysqli_fetch_assoc($invs_res)) {
    $invoices[$r['id']] = $r;
}

/* ── Fetch all items from all invoices and aggregate ── */
$items_raw = [];
$items_res = mysqli_query($conn, "
    SELECT ii.invoice_id, ii.product_name,
           SUM(ii.qty) AS total_qty,
           AVG(ii.price) AS avg_price
    FROM ushop_invoice_items ii
    WHERE ii.invoice_id IN ($ids_sql)
    GROUP BY ii.product_name
    ORDER BY ii.product_name ASC
");

$items_aggregated = [];
$grand_total = 0;

while ($item = mysqli_fetch_assoc($items_res)) {
    $qty = floatval($item['total_qty']);
    $price = floatval($item['avg_price']);
    $total = $qty * $price;
    $grand_total += $total;
    
    $items_aggregated[] = [
        'name' => $item['product_name'],
        'qty' => $qty,
        'price' => $price,
        'total' => $total
    ];
}

/* ── Get first invoice for header details ── */
$first_invoice = reset($invoices);
$invoice_date = $first_invoice['invoice_date'] ?? date('Y-m-d');
$invoice_no = $pi_row['invoice_no'] ?? 'N/A';

/* ── Format month/year ── */
$date_obj = new DateTime($invoice_date);
$month_year = $date_obj->format('F Y');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Invoice #<?php echo htmlspecialchars($invoice_no); ?> - Print</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: Arial, sans-serif;
            background: #f5f5f5;
            padding: 20px;
            color: #333;
        }
        
        @page {
            size: A4;
            margin: 0;
        }
        
        @media print {
            body {
                background: none;
                padding: 0;
            }
            .print-container {
                box-shadow: none !important;
                margin: 0 !important;
                width: auto !important;
                min-height: auto !important;
            }
        }
        
        .print-container {
            width: 210mm;
            min-height: 297mm;
            background: white;
            margin: 0 auto;
            padding: 15mm 18mm;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
            position: relative;
        }
        
        /* Header Section */
        .invoice-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 20px;
            border-bottom: 1px solid #ddd;
            padding-bottom: 15px;
        }
        
        .company-details {
            flex: 1;
        }
        
        .company-name {
            font-size: 18px;
            font-weight: bold;
            margin-bottom: 5px;
        }
        
        .company-address {
            font-size: 11px;
            line-height: 1.6;
            color: #555;
        }
        
        .invoice-meta {
            text-align: right;
            font-size: 11px;
            line-height: 1.8;
        }
        
        .invoice-meta-row {
            display: flex;
            justify-content: flex-end;
            gap: 20px;
        }
        
        .meta-label {
            font-weight: bold;
            min-width: 80px;
        }
        
        .meta-value {
            min-width: 100px;
        }
        
        /* Title */
        .invoice-title {
            text-align: center;
            font-size: 28px;
            font-weight: bold;
            margin: 25px 0 10px 0;
            text-decoration: underline;
        }
        
        /* Subtitle (Month) */
        .invoice-month {
            text-align: left;
            font-size: 13px;
            font-weight: bold;
            text-decoration: underline;
            margin: 15px 0 15px 0;
        }
        
        /* Items Table */
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin: 15px 0;
            font-size: 12px;
        }
        
        .items-table th {
            background: #f9f9f9;
            border: 1px solid #333;
            padding: 8px;
            text-align: left;
            font-weight: bold;
            border-bottom: 2px solid #333;
        }
        
        .items-table td {
            border: 1px solid #333;
            padding: 8px;
        }
        
        .items-table tr:nth-child(even) {
            background: #fafafa;
        }
        
        .td-qty, .td-price, .td-total {
            text-align: right;
            font-family: 'Courier New', monospace;
        }
        
        .total-row {
            background: #f0f0f0;
            font-weight: bold;
            border-top: 2px solid #333 !important;
        }
        
        .total-row td {
            border: 1px solid #333;
            padding: 10px 8px;
        }
        
        /* Signature Section */
        .signature-section {
            margin-top: 40px;
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
        }
        
        .thanks-text {
            font-size: 12px;
            margin-bottom: 30px;
        }
        
        .sig-block {
            text-align: center;
            flex: 1;
        }
        
        .sig-image {
            max-height: 60px;
            max-width: 150px;
            margin-bottom: 5px;
            object-fit: contain;
        }
        
        .sig-line {
            border-top: 2px dotted #999;
            width: 160px;
            margin: 5px auto;
        }
        
        .sig-label {
            font-size: 10px;
            margin-top: 3px;
            font-weight: bold;
        }
        
        /* Header/Footer Images */
        .page-header {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            width: 100%;
            height: 60px;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            padding: 0 35px;
        }
        
        .page-header img {
            max-height: 60px;
            max-width: 100%;
            object-fit: contain;
        }
        
        .page-footer {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            width: 100%;
            height: 50px;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            padding: 0 35px;
        }
        
        .page-footer img {
            max-height: 50px;
            max-width: 100%;
            object-fit: contain;
        }
        
        .content-wrapper {
            margin-top: 60px;
            margin-bottom: 60px;
        }
        
        /* Print button area */
        .print-button {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 10px 20px;
            background: #0084d4;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            z-index: 100;
        }
        
        .print-button:hover {
            background: #0066b3;
        }
        
        @media print {
            .print-button {
                display: none;
            }
        }
    </style>
</head>
<body>

<button class="print-button" onclick="window.print()">
    <i class="fas fa-print"></i> Print Invoice
</button>

<div class="print-container">
    
    <!-- Header Image (Letterhead Top) -->
    <?php if ($header_img && file_exists($header_img)): ?>
    <div class="page-header">
        <img src="<?php echo htmlspecialchars($header_img); ?>" alt="Header">
    </div>
    <?php endif; ?>
    
    <!-- Main Content -->
    <div class="content-wrapper">
        
        <!-- Invoice Header -->
        <div class="invoice-header">
            <div class="company-details">
                <?php if ($company_name): ?>
                <div class="company-name"><?php echo htmlspecialchars($company_name); ?>,</div>
                <?php endif; ?>
                <?php if ($first_invoice && $first_invoice['customer_name']): ?>
                <div class="company-address">
                    <?php echo htmlspecialchars($first_invoice['customer_name']); ?>
                </div>
                <?php endif; ?>
            </div>
            
            <div class="invoice-meta">
                <div class="invoice-meta-row">
                    <span class="meta-label">PO No</span>
                    <span class="meta-value">-</span>
                </div>
                <div class="invoice-meta-row">
                    <span class="meta-label">Invoice No</span>
                    <span class="meta-value"><?php echo htmlspecialchars($invoice_no); ?></span>
                </div>
                <div class="invoice-meta-row">
                    <span class="meta-label">Date</span>
                    <span class="meta-value"><?php echo htmlspecialchars($date_obj->format('d.m.Y')); ?></span>
                </div>
            </div>
        </div>
        
        <!-- Title -->
        <div class="invoice-title">Invoice</div>
        
        <!-- Month -->
        <div class="invoice-month">Month of <?php echo htmlspecialchars($month_year); ?></div>
        
        <!-- Items Table -->
        <table class="items-table">
            <thead>
                <tr>
                    <th>Item</th>
                    <th class="td-qty">Qty</th>
                    <th class="td-price">Price</th>
                    <th class="td-total">Total</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items_aggregated as $item): ?>
                <tr>
                    <td><?php echo htmlspecialchars($item['name']); ?></td>
                    <td class="td-qty"><?php echo rtrim(rtrim(number_format($item['qty'], 3, '.', ''), '0'), '.'); ?></td>
                    <td class="td-price"><?php echo number_format($item['price'], 2); ?></td>
                    <td class="td-total"><?php echo number_format($item['total'], 2); ?></td>
                </tr>
                <?php endforeach; ?>
                
                <!-- Total Row -->
                <tr class="total-row">
                    <td colspan="3" style="text-align: right;">Total Amount</td>
                    <td class="td-total"><?php echo number_format($grand_total, 2); ?></td>
                </tr>
            </tbody>
        </table>
        
        <!-- Signature Section -->
        <div class="signature-section">
            <div class="thanks-text">
                <strong>Thank you,</strong>
            </div>
        </div>
        
        <!-- Signature Block -->
        <div style="margin-top: 30px; display: flex; align-items: flex-end; gap: 20px;">
            <div class="sig-block">
                <?php if ($esign_img && file_exists($esign_img)): ?>
                <img src="<?php echo htmlspecialchars($esign_img); ?>" alt="Signature" class="sig-image">
                <?php else: ?>
                <div style="height: 60px; display: flex; align-items: center; justify-content: center; color: #ccc;">
                    [Signature]
                </div>
                <?php endif; ?>
                <div class="sig-line"></div>
                <div class="sig-label">Authorized Signatory</div>
            </div>
        </div>
        
    </div>
    
    <!-- Footer Image (Letterhead Bottom) -->
    <?php if ($footer_img && file_exists($footer_img)): ?>
    <div class="page-footer">
        <img src="<?php echo htmlspecialchars($footer_img); ?>" alt="Footer">
    </div>
    <?php endif; ?>
    
</div>

</body>
</html>