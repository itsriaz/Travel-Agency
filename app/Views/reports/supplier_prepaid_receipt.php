<?php

$receipt = is_array($receipt ?? null) ? $receipt : [];
$pageTitle = (string) ($pageTitle ?? 'Prepaid Supplier Payment Receipt');
$formatMoney = static fn (float $amount): string => number_format($amount, 2);
$statusLabel = ucwords(str_replace('_', ' ', (string) ($receipt['status'] ?? 'available')));
$referenceNo = trim((string) ($receipt['reference_no'] ?? ''));
$advanceNo = 'SADV-' . str_pad((string) ((int) ($receipt['id'] ?? 0)), 3, '0', STR_PAD_LEFT);
$customerName = trim((string) ($receipt['customer_name'] ?? ''));
$bookingReference = trim((string) ($receipt['booking_reference'] ?? ''));
$paidFrom = trim((string) ($receipt['treasury_account_name'] ?? ''));
$paymentMethod = trim((string) ($receipt['payment_method'] ?? ''));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?></title>
    <link rel="stylesheet" href="<?= e(asset('assets/css/app.css')) ?>">
    <style>
        body { background:#f3f6fb; color:#0f172a; }
        .receipt-shell { max-width:1040px; margin:24px auto; background:#fff; border:1px solid #d7e1ef; box-shadow:0 12px 28px rgba(15,23,42,.08); }
        .receipt-head { display:flex; justify-content:space-between; gap:16px; padding:24px; border-bottom:1px solid #d7e1ef; }
        .receipt-title { font-size:26px; font-weight:700; color:#12345b; }
        .receipt-subtitle { color:#4b647f; margin-top:4px; }
        .receipt-actions { display:flex; gap:10px; align-items:flex-start; }
        .receipt-grid { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:10px 12px; padding:24px; }
        .receipt-grid div { border:1px solid #e2e8f0; background:#f8fbff; padding:10px 12px; min-width:0; }
        .receipt-grid .receipt-span-2 { grid-column:span 2; }
        .receipt-grid .receipt-span-4 { grid-column:1 / -1; }
        .receipt-grid span { display:block; font-size:11px; text-transform:uppercase; letter-spacing:.04em; color:#5f738a; margin-bottom:4px; }
        .receipt-grid strong { display:block; font-size:15px; color:#10253f; line-height:1.35; overflow-wrap:anywhere; word-break:break-word; }
        .receipt-note { padding:0 24px 24px; color:#29415f; }
        .receipt-note-card { border:1px solid #e2e8f0; background:#f8fbff; padding:12px 14px; }
        .receipt-note-card strong { display:block; margin-bottom:6px; color:#10253f; }
        @media (max-width: 900px) {
            .receipt-grid { grid-template-columns:repeat(2,minmax(0,1fr)); }
            .receipt-grid .receipt-span-2 { grid-column:span 2; }
        }
        @media print {
            body { background:#fff; }
            .receipt-shell { max-width:none; margin:0; border:none; box-shadow:none; }
            .receipt-actions { display:none; }
        }
    </style>
</head>
<body>
<section class="receipt-shell">
    <header class="receipt-head">
        <div>
            <div class="receipt-title">Prepaid Supplier Payment Receipt</div>
            <div class="receipt-subtitle">Supplier advance / prepaid payment document</div>
        </div>
        <div class="receipt-actions">
            <a class="btn btn-sm" href="<?= e(url('/reports?report=supplier_prepaid_payments')) ?>">Back to Report</a>
            <button class="btn btn-primary btn-sm" type="button" onclick="window.print()">Print</button>
        </div>
    </header>
    <div class="receipt-grid">
        <div><span>Advance No.</span><strong><?= e($advanceNo) ?></strong></div>
        <div><span>Reference</span><strong><?= e($referenceNo !== '' ? $referenceNo : 'N/A') ?></strong></div>
        <div><span>Payment Date</span><strong><?= e((string) ($receipt['payment_date'] ?? '')) ?></strong></div>
        <div><span>Branch</span><strong><?= e((string) ($receipt['branch_name'] ?? '')) ?></strong></div>
        <div class="receipt-span-2"><span>Supplier</span><strong><?= e((string) ($receipt['supplier_name'] ?? 'Supplier')) ?></strong></div>
        <div><span>Customer</span><strong><?= e($customerName !== '' ? $customerName : 'N/A') ?></strong></div>
        <div><span>Booking</span><strong><?= e($bookingReference !== '' ? $bookingReference : 'N/A') ?></strong></div>
        <div><span>Currency</span><strong><?= e((string) ($receipt['currency'] ?? 'PKR')) ?></strong></div>
        <div><span>Payment Method</span><strong><?= e(ucwords(str_replace('_', ' ', (string) ($paymentMethod !== '' ? $paymentMethod : 'Legacy / not recorded')))) ?></strong></div>
        <div class="receipt-span-2"><span>Paid From</span><strong><?= e($paidFrom !== '' ? $paidFrom : 'Legacy / not recorded') ?></strong></div>
        <div><span>Status</span><strong><?= e($statusLabel) ?></strong></div>
        <div><span>Advance Paid</span><strong><?= e($formatMoney((float) ($receipt['deposit_amount'] ?? 0))) ?></strong></div>
        <div><span>Advance Used</span><strong><?= e($formatMoney((float) ($receipt['used_amount'] ?? 0))) ?></strong></div>
        <div><span>Available Balance</span><strong><?= e($formatMoney((float) ($receipt['available_amount'] ?? 0))) ?></strong></div>
    </div>
    <div class="receipt-note">
        <div class="receipt-note-card">
            <strong>Remarks</strong>
            <?= e((string) (($receipt['remarks'] ?? '') !== '' ? $receipt['remarks'] : 'N/A')) ?>
        </div>
    </div>
</section>
</body>
</html>
