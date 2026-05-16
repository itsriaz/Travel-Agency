<?php

$booking = is_array($booking ?? null) ? $booking : [];
$travelers = is_array($travelers ?? null) ? $travelers : [];
$services = is_array($services ?? null) ? $services : [];
$customerPaymentFoundation = is_array($customerPaymentFoundation ?? null) ? $customerPaymentFoundation : [];
$supplierFoundation = is_array($supplierFoundation ?? null) ? $supplierFoundation : [];
$selectedReceipt = is_array($selectedReceipt ?? null) ? $selectedReceipt : null;
$selectedSupplierPayment = is_array($selectedSupplierPayment ?? null) ? $selectedSupplierPayment : null;
$branchBranding = is_array($branchBranding ?? null) ? $branchBranding : [];
$summary = is_array($summary ?? null) ? $summary : [];
$outputType = (string) ($outputType ?? 'invoice');
$outputTypeLabel = (string) ($outputTypeLabel ?? 'Operational Output');
$generatedAt = (string) ($generatedAt ?? '');
$backUrl = (string) ($backUrl ?? url('/workspace'));
$showOutputDebug = config('app.debug', false) && (string) ($_GET['debug_ui'] ?? '') === '1';

$formatMoney = static fn (float $amount): string => number_format($amount, 2);
$formatCurrencyTotals = static function (array $totals) use ($formatMoney): string {
    if ($totals === []) {
        return 'PKR 0.00';
    }

    $parts = [];
    foreach ($totals as $currency => $amount) {
        $parts[] = trim((string) $currency) . ' ' . $formatMoney((float) $amount);
    }

    return implode(' / ', $parts);
};
$leadTravelerName = trim((string) ($booking['lead_traveler_name'] ?? ''));
$customerName = $leadTravelerName !== '' ? $leadTravelerName : trim((string) ($booking['party_label'] ?? 'Booking Party'));
$bookingReference = (string) ($booking['booking_reference'] ?? '');
$bookingDate = (string) ($booking['booking_date'] ?? '');
$bookingDueDate = trim((string) ($booking['due_date'] ?? ''));
$customerMobile = trim((string) ($booking['contact_mobile'] ?? ''));
$customerPassport = trim((string) ($booking['passport_number'] ?? ''));
$travelerCount = count($travelers);
$serviceCount = count($services);
$serviceTypeLabels = [];
foreach ($services as $service) {
    $serviceType = ucwords((string) ($service['service_type'] ?? 'Service'));
    if ($serviceType !== '') {
        $serviceTypeLabels[$serviceType] = true;
    }
}
$serviceSummary = 'General Services';
if ($serviceTypeLabels !== []) {
    $serviceNames = array_keys($serviceTypeLabels);
    $serviceSummary = count($serviceNames) === 1 ? $serviceNames[0] : 'Mixed Services';
}
$receiptRemarks = trim((string) ($selectedReceipt['remarks'] ?? ''));
$receiptReference = trim((string) ($selectedReceipt['referenceNumber'] ?? ''));
$receiptBankCard = trim((string) ($selectedReceipt['bankCardDetail'] ?? ''));
$receiptAgainst = trim($receiptRemarks !== '' ? $receiptRemarks : $serviceSummary);
$receivedBy = trim((string) ($booking['updated_by_name'] ?? $booking['created_by_name'] ?? 'Authorized Staff'));
$passportNumber = trim((string) ($booking['passport_number'] ?? ''));
if ($passportNumber === '') {
    foreach ($travelers as $traveler) {
        $travelerPassport = trim((string) ($traveler['passport_number'] ?? ''));
        if ($travelerPassport !== '') {
            $passportNumber = $travelerPassport;
            break;
        }
    }
}
$invoiceReceivableTotals = is_array($customerPaymentFoundation['summary']['invoiceReceivable'] ?? null)
    ? $customerPaymentFoundation['summary']['invoiceReceivable']
    : [];
$invoiceOutstandingTotals = is_array($customerPaymentFoundation['summary']['invoiceOutstanding'] ?? null)
    ? $customerPaymentFoundation['summary']['invoiceOutstanding']
    : [];
$invoiceReceivedTotals = is_array($customerPaymentFoundation['summary']['invoiceReceived'] ?? null)
    ? $customerPaymentFoundation['summary']['invoiceReceived']
    : [];
$customerPreviousBalanceTotals = is_array($customerPaymentFoundation['summary']['previousBalance'] ?? null)
    ? $customerPaymentFoundation['summary']['previousBalance']
    : [];
$customerCreditTotals = is_array($customerPaymentFoundation['summary']['customerCredit'] ?? null)
    ? $customerPaymentFoundation['summary']['customerCredit']
    : [];
$supplierOutstandingTotals = is_array($summary['supplierOutstanding'] ?? null) ? $summary['supplierOutstanding'] : [];
$supplierPaidTotals = is_array($summary['supplierPaid'] ?? null) ? $summary['supplierPaid'] : [];
$invoiceServiceSummary = $serviceSummary;
$documentAudienceNote = match ($outputType) {
    'invoice' => 'Customer-facing invoice generated from the current saved booking services.',
    'account_statement' => 'Customer account statement for this booking and its saved receipts.',
    'itinerary' => 'Travel service summary for passenger and booking reference use.',
    'booking_confirmation' => 'Booking confirmation generated from the current saved booking record.',
    'supplier_voucher' => 'Supplier-facing or finance-facing payment voucher for the selected supplier payment.',
    default => 'Operational output generated from the current saved booking record.',
};

$cleanServiceDescription = static function (array $service): string {
    $sector = trim((string) (($service['sector_from'] ?? '') . (((string) ($service['sector_to'] ?? '') !== '') ? ' - ' . (string) ($service['sector_to'] ?? '') : '')));
    if ($sector !== '') {
        return $sector;
    }

    $remarks = trim((string) ($service['remarks'] ?? ''));
    $ticketRemarks = trim((string) ($service['ticket_remarks'] ?? ''));
    $rawDescription = $ticketRemarks !== '' ? $ticketRemarks : $remarks;
    $normalizedRaw = mb_strtolower($rawDescription);
    $blockedSnippets = [
        'enter first service here',
        'invoice will be saved with it',
        'save invoice first',
        'create the first persisted service line',
        'persisted service line',
        'workspace',
    ];
    foreach ($blockedSnippets as $blockedSnippet) {
        if ($normalizedRaw !== '' && str_contains($normalizedRaw, $blockedSnippet)) {
            $rawDescription = '';
            break;
        }
    }

    if ($rawDescription !== '') {
        return $rawDescription;
    }

    return match (mb_strtolower((string) ($service['service_type'] ?? ''))) {
        'air ticket' => 'Air Ticket Service',
        'visa' => 'Visa Service',
        'hotel' => 'Hotel Service',
        'umrah' => 'Umrah Service',
        'transport' => 'Transport Service',
        'tour', 'tourism' => 'Tour Service',
        default => 'Travel Service',
    };
};
$receiptCurrency = (string) ($selectedReceipt['currency'] ?? 'PKR');
$totalInvoiceAmount = (float) ($invoiceReceivableTotals[$receiptCurrency] ?? 0);
$receiptCurrencyReceivedTotal = (float) ($invoiceReceivedTotals[$receiptCurrency] ?? 0);
$serviceCurrencyFallbackTotal = 0.0;
if ($totalInvoiceAmount <= 0) {
    foreach ($services as $service) {
        if ((string) ($service['currency'] ?? '') === $receiptCurrency) {
            $savedFinalSale = (float) ($service['final_sale_price'] ?? 0);
            if (abs($savedFinalSale) > 0.005) {
                $serviceCurrencyFallbackTotal += $savedFinalSale;
                continue;
            }

            $serviceCurrencyFallbackTotal += (float) ($service['sale_price'] ?? 0)
                + (float) ($service['service_charge'] ?? 0)
                - (float) ($service['discount_amount'] ?? 0);
        }
    }
    $totalInvoiceAmount = $serviceCurrencyFallbackTotal;
}

$balanceDue = max($totalInvoiceAmount - $receiptCurrencyReceivedTotal, 0);
$dueDateForReceipt = '';
if ($balanceDue > 0) {
    foreach (($customerPaymentFoundation['openReceivables'] ?? []) as $openReceivableRow) {
        if ((string) ($openReceivableRow['currency'] ?? '') === $receiptCurrency && (float) ($openReceivableRow['outstandingAmount'] ?? 0) > 0 && ! empty($openReceivableRow['nextDueDate'])) {
            $dueDateForReceipt = (string) $openReceivableRow['nextDueDate'];
            break;
        }
    }
}
$receiptDebug = [
    'receiptCurrency' => $receiptCurrency,
    'invoiceReceivableTotals' => $invoiceReceivableTotals,
    'invoiceOutstandingTotals' => $invoiceOutstandingTotals,
    'invoiceReceivedTotals' => $invoiceReceivedTotals,
    'serviceCurrencyFallbackTotal' => $serviceCurrencyFallbackTotal,
    'receiptCurrencyReceivedTotal' => $receiptCurrencyReceivedTotal,
    'serviceRowsCount' => count($services),
    'services' => array_map(
        static fn (array $service): array => [
            'currency' => (string) ($service['currency'] ?? ''),
            'sale_price' => (float) ($service['sale_price'] ?? 0),
            'service_charge' => (float) ($service['service_charge'] ?? 0),
            'discount_amount' => (float) ($service['discount_amount'] ?? 0),
            'final_sale_price' => (float) ($service['final_sale_price'] ?? 0),
            'line_reference' => (string) ($service['line_reference'] ?? ''),
        ],
        $services
    ),
    'openReceivables' => array_map(
        static fn (array $row): array => [
            'currency' => (string) ($row['currency'] ?? ''),
            'dueAmount' => (float) ($row['dueAmount'] ?? 0),
            'outstandingAmount' => (float) ($row['outstandingAmount'] ?? 0),
            'nextDueDate' => (string) ($row['nextDueDate'] ?? ''),
            'serviceLineReference' => (string) ($row['serviceLineReference'] ?? ''),
        ],
        $customerPaymentFoundation['openReceivables'] ?? []
    ),
];
$statementInvoiceCurrencies = [];
foreach (array_keys($invoiceReceivableTotals) as $currency) {
    $statementInvoiceCurrencies[(string) $currency] = true;
}
foreach (array_keys($invoiceOutstandingTotals) as $currency) {
    $statementInvoiceCurrencies[(string) $currency] = true;
}
if ($statementInvoiceCurrencies === []) {
    foreach ($services as $service) {
        $serviceCurrency = trim((string) ($service['currency'] ?? ''));
        if ($serviceCurrency !== '') {
            $statementInvoiceCurrencies[$serviceCurrency] = true;
        }
    }
}
$statementSameCurrencyPreviousTotals = [];
$statementOtherCurrencyPreviousTotals = [];
foreach ($customerPreviousBalanceTotals as $currency => $amount) {
    if (isset($statementInvoiceCurrencies[(string) $currency])) {
        $statementSameCurrencyPreviousTotals[(string) $currency] = (float) $amount;
    } elseif (abs((float) $amount) > 0.005) {
        $statementOtherCurrencyPreviousTotals[(string) $currency] = (float) $amount;
    }
}
$statementTotalOutstandingTotals = $invoiceOutstandingTotals;
foreach ($statementSameCurrencyPreviousTotals as $currency => $amount) {
    $statementTotalOutstandingTotals[(string) $currency] = (float) ($statementTotalOutstandingTotals[(string) $currency] ?? 0) + (float) $amount;
}
$statementDisplayCurrency = $statementInvoiceCurrencies !== []
    ? (string) array_key_first($statementInvoiceCurrencies)
    : ((string) array_key_first($customerPreviousBalanceTotals) !== '' ? (string) array_key_first($customerPreviousBalanceTotals) : 'PKR');
$statementPreviousBalanceDisplay = $statementSameCurrencyPreviousTotals !== []
    ? $formatCurrencyTotals($statementSameCurrencyPreviousTotals)
    : ($statementDisplayCurrency . ' ' . $formatMoney(0));
$nonZeroCurrencyTotals = static function (array $totals): array {
    $filtered = [];
    foreach ($totals as $currency => $amount) {
        if (abs((float) $amount) > 0.005) {
            $filtered[(string) $currency] = round((float) $amount, 2);
        }
    }

    return $filtered;
};
$remainingCustomerBalanceSource = is_array($customerPaymentFoundation['summary']['fullCustomerOutstanding'] ?? null)
    ? $customerPaymentFoundation['summary']['fullCustomerOutstanding']
    : [];
$remainingCustomerBalanceTotals = $nonZeroCurrencyTotals($remainingCustomerBalanceSource);
$selectedReceiptId = (int) ($selectedReceipt['id'] ?? 0);
$selectedReceiptNo = (string) ($selectedReceipt['receiptNo'] ?? '');
$receiptAllocations = array_values(array_filter(
    $customerPaymentFoundation['allocations'] ?? [],
    static fn (array $allocation): bool => (int) ($allocation['receiptId'] ?? 0) === $selectedReceiptId
        || ((string) ($allocation['receiptNo'] ?? '') !== '' && (string) ($allocation['receiptNo'] ?? '') === $selectedReceiptNo)
));
$currentBookingReceiptAllocations = array_values(array_filter(
    $receiptAllocations,
    static fn (array $allocation): bool => (string) ($allocation['bookingReference'] ?? '') === $bookingReference
));
$currentInvoicePaidByReceiptTotals = [];
foreach ($currentBookingReceiptAllocations as $allocation) {
    $currency = (string) ($allocation['receivableCurrency'] ?? $allocation['currency'] ?? '');
    if ($currency === '') {
        continue;
    }

    $currentInvoicePaidByReceiptTotals[$currency] = ($currentInvoicePaidByReceiptTotals[$currency] ?? 0.0)
        + (float) ($allocation['receivableAmountAllocated'] ?? $allocation['allocatedAmount'] ?? 0);
}
$receiptAllocationTotals = [];
foreach ($receiptAllocations as $allocation) {
    $currency = (string) ($allocation['paymentCurrency'] ?? $allocation['receivableCurrency'] ?? $allocation['currency'] ?? '');
    if ($currency === '') {
        continue;
    }

    $receiptAllocationTotals[$currency] = ($receiptAllocationTotals[$currency] ?? 0.0)
        + (float) ($allocation['paymentAmountConsumed'] ?? $allocation['allocatedAmount'] ?? 0);
}
$currentInvoiceBalanceDisplay = $nonZeroCurrencyTotals($invoiceOutstandingTotals);
$currentInvoiceAmountDisplay = $nonZeroCurrencyTotals($invoiceReceivableTotals);
$receiptTotalAllocated = (float) ($selectedReceipt['allocatedAmount'] ?? 0);
$receiptUnallocatedAmount = (float) ($selectedReceipt['unallocatedAmount'] ?? 0);
$invoicePaymentHistoryRows = array_values(array_filter(
    $customerPaymentFoundation['invoicePaymentHistory'] ?? [],
    static fn (array $allocation): bool => (string) ($allocation['bookingReference'] ?? '') === $bookingReference
));
$isSelectedReceiptHistoryRow = static function (array $historyRow) use ($selectedReceiptId, $selectedReceiptNo): bool {
    $historyReceiptId = (int) ($historyRow['receiptId'] ?? 0);
    $historyReceiptNo = trim((string) ($historyRow['receiptNo'] ?? ''));

    if ($selectedReceiptId > 0 && $historyReceiptId > 0) {
        return $historyReceiptId === $selectedReceiptId;
    }

    return $selectedReceiptNo !== '' && $historyReceiptNo !== '' && $historyReceiptNo === $selectedReceiptNo;
};

$previousInvoicePaymentsTotals = [];
$currentInvoicePaymentHistoryTotals = [];
$totalPaidAgainstInvoiceTotals = [];

foreach ($invoicePaymentHistoryRows as $historyRow) {
    $currency = (string) ($historyRow['receivableCurrency'] ?? $historyRow['currency'] ?? '');
    if ($currency === '') {
        continue;
    }

    $paidAmount = (float) ($historyRow['receivableAmountAllocated'] ?? $historyRow['allocatedAmount'] ?? 0);
    $totalPaidAgainstInvoiceTotals[$currency] = ($totalPaidAgainstInvoiceTotals[$currency] ?? 0.0) + $paidAmount;

    if ($isSelectedReceiptHistoryRow($historyRow)) {
        $currentInvoicePaymentHistoryTotals[$currency] = ($currentInvoicePaymentHistoryTotals[$currency] ?? 0.0) + $paidAmount;
    } else {
        $previousInvoicePaymentsTotals[$currency] = ($previousInvoicePaymentsTotals[$currency] ?? 0.0) + $paidAmount;
    }
}

$previousInvoicePaymentsDisplay = $nonZeroCurrencyTotals($previousInvoicePaymentsTotals);
$currentInvoicePaymentHistoryDisplay = $nonZeroCurrencyTotals($currentInvoicePaymentHistoryTotals);
$totalPaidAgainstInvoiceDisplay = $nonZeroCurrencyTotals($totalPaidAgainstInvoiceTotals);
?>
<main class="output-page">
    <div class="output-toolbar no-print">
        <a class="btn btn-sm" href="<?= e($backUrl) ?>">Back to Workspace</a>
        <button class="btn btn-primary btn-sm" type="button" onclick="window.print()">Print</button>
    </div>

    <?php if ($outputType !== 'customer_receipt'): ?>
        <section class="output-sheet">
            <header class="output-head">
                <div>
                    <div class="output-branch"><?= e((string) ($branchBranding['name'] ?? 'Travel Agency Branch')) ?></div>
                    <div class="output-branch-meta">
                        <?= e(trim((string) (($branchBranding['city'] ?? '') . ((string) ($branchBranding['country'] ?? '') !== '' ? ', ' . (string) ($branchBranding['country'] ?? '') : '')))) ?>
                    </div>
                    <div class="output-branch-tagline"><?= e((string) ($branchBranding['tagline'] ?? 'Travel Agency Operations')) ?></div>
                </div>
                <div class="output-doc-meta">
                    <div class="output-doc-title"><?= e($outputTypeLabel) ?></div>
                    <div><strong>Booking / Invoice:</strong> <?= e($bookingReference) ?></div>
                    <div><strong>Generated:</strong> <?= e($generatedAt) ?></div>
                    <div><strong>Base Currency:</strong> <?= e((string) ($branchBranding['baseCurrency'] ?? 'PKR')) ?></div>
                </div>
            </header>
            <section class="output-top-grid">
                <div class="output-block">
                    <h2>Document Details</h2>
                    <div class="output-kv-grid">
                        <div><span>Booking / Invoice No.</span><strong><?= e($bookingReference !== '' ? $bookingReference : 'N/A') ?></strong></div>
                        <div><span>Invoice Date</span><strong><?= e($bookingDate !== '' ? $bookingDate : 'N/A') ?></strong></div>
                        <div><span>Due Date</span><strong><?= e($bookingDueDate !== '' ? $bookingDueDate : 'N/A') ?></strong></div>
                        <div><span>Service Summary</span><strong><?= e($invoiceServiceSummary) ?></strong></div>
                        <div><span>Passengers</span><strong><?= e((string) $travelerCount) ?></strong></div>
                        <div><span>Service Rows</span><strong><?= e((string) $serviceCount) ?></strong></div>
                    </div>
                </div>
                <div class="output-block">
                    <h2><?= e($outputType === 'supplier_voucher' ? 'Supplier / Booking' : 'Customer / Party') ?></h2>
                    <div class="output-kv-grid">
                        <div><span>Customer Name</span><strong><?= e($customerName) ?></strong></div>
                        <div><span>Party Label</span><strong><?= e((string) (($booking['party_label'] ?? '') !== '' ? $booking['party_label'] : 'Booking Party')) ?></strong></div>
                        <div><span>Mobile</span><strong><?= e($customerMobile !== '' ? $customerMobile : 'N/A') ?></strong></div>
                        <div><span>Passport No.</span><strong><?= e($customerPassport !== '' ? $customerPassport : 'N/A') ?></strong></div>
                        <div><span>Branch</span><strong><?= e((string) ($branchBranding['name'] ?? 'Travel Agency Branch')) ?></strong></div>
                        <div><span>Remarks</span><strong><?= e((string) (($booking['remarks'] ?? '') !== '' ? $booking['remarks'] : 'N/A')) ?></strong></div>
                    </div>
                </div>
            </section>
            <section class="output-note-bar">
                <?= e($documentAudienceNote) ?>
            </section>
        <?php endif; ?>

        <?php if ($outputType === 'invoice'): ?>
            <section class="output-block">
                <h2>Invoice Services</h2>
                <table class="output-table">
                    <thead><tr><th>Line</th><th>Service</th><th>Passenger</th><th>Reference</th><th>Sector / Description</th><th>Currency</th><th>Amount</th></tr></thead>
                    <tbody>
                    <?php foreach ($services as $service): ?>
                        <?php
                        $serviceReference = trim((string) ($service['ticket_number'] ?? $service['line_reference'] ?? ''));
                        $servicePassenger = trim((string) ($service['passenger_name'] ?? ''));
                        $serviceAmount = (float) ($service['sale_price'] ?? 0)
                            + (float) ($service['service_charge'] ?? 0)
                            - (float) ($service['discount_amount'] ?? 0);
                        ?>
                        <tr>
                            <td><?= e((string) ($service['line_reference'] ?? '')) ?></td>
                            <td><?= e(ucwords((string) ($service['service_type'] ?? 'service'))) ?></td>
                            <td><?= e($servicePassenger !== '' ? $servicePassenger : $customerName) ?></td>
                            <td><?= e($serviceReference !== '' ? $serviceReference : 'N/A') ?></td>
                            <td><?= e($cleanServiceDescription($service)) ?></td>
                            <td><?= e((string) ($service['currency'] ?? 'PKR')) ?></td>
                            <td><?= e($formatMoney($serviceAmount)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($services === []): ?><tr><td colspan="7">No service lines recorded for this invoice yet.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </section>
            <section class="output-block">
                <h2>Invoice Summary</h2>
                <div class="output-summary-strip">
                    <div><span>Total Invoice Amount</span><strong><?= e($formatCurrencyTotals($invoiceReceivableTotals)) ?></strong></div>
                    <div><span>Payment Received</span><strong><?= e($formatCurrencyTotals($invoiceReceivedTotals)) ?></strong></div>
                    <div><span>Balance Due</span><strong><?= e($formatCurrencyTotals($invoiceOutstandingTotals)) ?></strong></div>
                </div>
            </section>
        <?php endif; ?>

        <?php if ($outputType === 'customer_receipt' && $selectedReceipt !== null): ?>
            <section class="receipt-sheet">
                <header class="receipt-sheet__head">
                    <div>
                        <div class="receipt-sheet__branch"><?= e((string) ($branchBranding['name'] ?? 'Travel Agency Branch')) ?></div>
                        <div class="receipt-sheet__meta"><?= e(trim((string) (($branchBranding['city'] ?? '') . ((string) ($branchBranding['country'] ?? '') !== '' ? ', ' . (string) ($branchBranding['country'] ?? '') : '')))) ?></div>
                        <div class="receipt-sheet__meta"><?= e((string) ($branchBranding['tagline'] ?? 'Travel Agency Operations')) ?></div>
                    </div>
                    <div class="receipt-sheet__title-wrap">
                        <div class="receipt-sheet__title">Customer Payment Receipt</div>
                        <div class="receipt-sheet__subtitle">Payment acknowledgment for travel services</div>
                    </div>
                </header>

                <section class="receipt-card">
                    <div class="receipt-card__grid">
                        <div><span>Receipt No.</span><strong><?= e((string) ($selectedReceipt['receiptNo'] ?? '')) ?></strong></div>
                        <div><span>Receipt Date</span><strong><?= e((string) ($selectedReceipt['receiptDate'] ?? '')) ?></strong></div>
                        <div><span>Booking / Invoice No.</span><strong><?= e((string) ($booking['booking_reference'] ?? '')) ?></strong></div>
                        <div><span>Customer Name</span><strong><?= e($customerName) ?></strong></div>
                        <?php if ($passportNumber !== ''): ?>
                            <div><span>Passport No.</span><strong><?= e($passportNumber) ?></strong></div>
                        <?php endif; ?>
                        <div><span>Mobile</span><strong><?= e((string) (($booking['contact_mobile'] ?? '') !== '' ? $booking['contact_mobile'] : 'N/A')) ?></strong></div>
                        <div><span>Currency</span><strong><?= e($receiptCurrency) ?></strong></div>
                        <div><span>Payment Method</span><strong><?= e(ucwords(str_replace('_', ' ', (string) ($selectedReceipt['paymentMethod'] ?? '')))) ?></strong></div>
                        <div><span>Reference No.</span><strong><?= e($receiptReference !== '' ? $receiptReference : 'N/A') ?></strong></div>
                        <div><span>Received Against</span><strong><?= e($receiptAgainst) ?></strong></div>
                        <div><span>Service Summary</span><strong><?= e($serviceSummary) ?></strong></div>
                        <?php if ($receiptBankCard !== ''): ?>
                            <div><span>Bank / Card Detail</span><strong><?= e($receiptBankCard) ?></strong></div>
                        <?php endif; ?>
                    </div>
                </section>

                <section class="receipt-card receipt-card--finance">
                    <div class="receipt-finance-strip">
                        <div>
                            <span>Payment Received</span>
                            <strong><?= e($receiptCurrency) ?> <?= e($formatMoney((float) ($selectedReceipt['receivedAmount'] ?? 0))) ?></strong>
                        </div>
                        <div>
                            <span>Credit / Return</span>
                            <strong><?= e($receiptCurrency) ?> <?= e($formatMoney($receiptUnallocatedAmount)) ?></strong>
                        </div>
                    </div>
                    <?php if ($currentInvoiceAmountDisplay !== [] || $currentInvoicePaidByReceiptTotals !== [] || $currentInvoiceBalanceDisplay !== []): ?>
                        <div class="receipt-due-line" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-top:12px;">
                            <div>
                                <span>Current Invoice No.</span>
                                <strong><?= e($bookingReference !== '' ? $bookingReference : 'N/A') ?></strong>
                            </div>
                            <div>
                                <span>Current Invoice Amount</span>
                                <strong><?= e($formatCurrencyTotals($currentInvoiceAmountDisplay !== [] ? $currentInvoiceAmountDisplay : $invoiceReceivableTotals)) ?></strong>
                            </div>
                           <?php if ($previousInvoicePaymentsDisplay !== []): ?>
    <div>
        <span>Previous Payments</span>
        <strong><?= e($formatCurrencyTotals($previousInvoicePaymentsDisplay)) ?></strong>
    </div>
<?php endif; ?>
                            <div>
                                <span>Current Payment</span>
                                <strong><?= e($formatCurrencyTotals($currentInvoicePaidByReceiptTotals !== [] ? $currentInvoicePaidByReceiptTotals : [$receiptCurrency => 0])) ?></strong>
                            </div>
                            <div>
                                <span>Total Paid</span>
                                <strong><?= e($formatCurrencyTotals($totalPaidAgainstInvoiceDisplay !== [] ? $totalPaidAgainstInvoiceDisplay : [$receiptCurrency => 0])) ?></strong>
                            </div>
                        </div>
                        <div class="receipt-due-line" style="display:grid;grid-template-columns:minmax(0,1fr) minmax(180px,auto);gap:12px;margin-top:12px;align-items:end;">
                            <div>
                                <span>Invoice Balance</span>
                                <strong><?= e($formatCurrencyTotals($currentInvoiceBalanceDisplay !== [] ? $currentInvoiceBalanceDisplay : [$receiptCurrency => 0])) ?></strong>
                            </div>
                            <?php if ($dueDateForReceipt !== ''): ?>
                                <div>
                                    <span>Due Date</span>
                                    <strong><?= e($dueDateForReceipt) ?></strong>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    <?php if ($showOutputDebug): ?>
                    <div style="margin-top:12px;padding:10px;border:1px dashed #c96;background:#fff8ef;font-size:11px;white-space:pre-wrap;">
Receipt debug
receiptCurrency: <?= e($receiptCurrency) . "\n" ?>
invoiceReceivableTotals: <?= e(json_encode($invoiceReceivableTotals, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}') . "\n" ?>
invoiceOutstandingTotals: <?= e(json_encode($invoiceOutstandingTotals, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}') . "\n" ?>
invoiceReceivedTotals: <?= e(json_encode($invoiceReceivedTotals, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}') . "\n" ?>
serviceCurrencyFallbackTotal: <?= e($formatMoney($serviceCurrencyFallbackTotal)) . "\n" ?>
receiptCurrencyReceivedTotal: <?= e($formatMoney($receiptCurrencyReceivedTotal)) . "\n" ?>
serviceRowsCount: <?= e((string) count($services)) . "\n" ?>
services: <?= e(json_encode($receiptDebug['services'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '[]') . "\n" ?>
openReceivables: <?= e(json_encode($receiptDebug['openReceivables'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '[]') ?>
                    </div>
                    <?php endif; ?>
                </section>

                <section class="receipt-card">
                    <h2 style="margin:0 0 12px;">Invoice Payment History</h2>
                    <table class="output-table receipt-table">
                        <thead><tr><th>Receipt No.</th><th>Receipt Date</th><th>Payment Currency</th><th>Allocated to This Invoice</th><th>Current Receipt</th></tr></thead>
                        <tbody>
                        <?php if ($invoicePaymentHistoryRows === []): ?>
                            <tr><td colspan="5">No payment history is available for this invoice yet.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($invoicePaymentHistoryRows as $historyRow): ?>
                            <?php
                            $historyCurrency = (string) ($historyRow['receivableCurrency'] ?? $historyRow['currency'] ?? $receiptCurrency);
                            $historyAmount = (float) ($historyRow['receivableAmountAllocated'] ?? $historyRow['allocatedAmount'] ?? 0);
                            ?>
                            <tr>
                                <td><?= e((string) ($historyRow['receiptNo'] ?? '')) ?></td>
                                <td><?= e((string) ($historyRow['receiptDate'] ?? '')) ?></td>
                                <td><?= e((string) ($historyRow['paymentCurrency'] ?? $receiptCurrency)) ?></td>
                                <td><?= e($historyCurrency) ?> <?= e($formatMoney($historyAmount)) ?></td>
                                <td><?= e((int) ($historyRow['receiptId'] ?? 0) === $selectedReceiptId ? 'Current Receipt' : 'Previous Payment') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </section>

                <section class="receipt-card">
                    <h2 style="margin:0 0 12px;">Details</h2>
                    <table class="output-table receipt-table">
                        <thead><tr><th>#</th><th>Invoice No.</th><th>Service</th><th>Passenger</th><th>Type</th><th>Currency</th><th>Amount</th><th>Balance</th></tr></thead>
                        <tbody>
                        <?php if ($receiptAllocations === []): ?>
                            <tr><td colspan="8">No allocation rows were posted for this receipt yet.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($receiptAllocations as $index => $allocation): ?>
                            <?php
                            $allocationCurrency = (string) ($allocation['receivableCurrency'] ?? $allocation['currency'] ?? $receiptCurrency);
                            $allocationAmount = (float) ($allocation['receivableAmountAllocated'] ?? $allocation['allocatedAmount'] ?? 0);
                            $remainingAmount = (float) ($allocation['currentOutstandingAmount'] ?? 0);
                            $allocationType = (string) ($allocation['allocationType'] ?? 'Allocated');
                            $allocationPassenger = trim((string) ($allocation['passengerName'] ?? ''));
                            ?>
                            <tr>
                                <td><?= e((string) ($index + 1)) ?></td>
                                <td><?= e((string) ($allocation['bookingReference'] ?? 'N/A')) ?></td>
                                <td><?= e((string) ($allocation['serviceType'] ?? 'Service')) ?></td>
                                <td><?= e($allocationPassenger !== '' ? $allocationPassenger : $customerName) ?></td>
                                <td><?= e($allocationType === 'Previous Outstanding' ? 'Previous Balance' : ($allocationType === 'Customer Credit / Unallocated' ? 'Customer Credit' : $allocationType)) ?></td>
                                <td><?= e($allocationCurrency) ?></td>
                                <td><?= e($allocationCurrency) ?> <?= e($formatMoney($allocationAmount)) ?></td>
                                <td><?= e($allocationCurrency) ?> <?= e($formatMoney($remainingAmount)) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </section>

                <section class="receipt-card">
                    <h2 style="margin:0 0 12px;">Outstanding Balance</h2>
                    <?php if ($remainingCustomerBalanceTotals === []): ?>
                        <div class="receipt-due-line">
                            <span>Outstanding Balance</span>
                            <strong>0.00</strong>
                        </div>
                    <?php else: ?>
                        <div class="receipt-finance-strip">
                            <?php foreach ($remainingCustomerBalanceTotals as $currency => $amount): ?>
                                <div>
                                    <span><?= e($currency) ?></span>
                                    <strong><?= e($currency) ?> <?= e($formatMoney((float) $amount)) ?></strong>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>

                <?php if ($services !== []): ?>
                    <section class="receipt-card">
                        <table class="output-table receipt-table">
                            <thead><tr><th>Service Type</th><th>Service Reference</th><th>Passenger</th><th>Sector / Description</th></tr></thead>
                            <tbody>
                            <?php foreach ($services as $service): ?>
                                <?php
                                $serviceReference = trim((string) ($service['ticket_number'] ?? $service['line_reference'] ?? ''));
                                $passenger = trim((string) ($service['traveler_name'] ?? $service['passenger_name'] ?? ''));
                                $description = $cleanServiceDescription($service);
                                ?>
                                <tr>
                                    <td><?= e(ucwords((string) ($service['service_type'] ?? 'Service'))) ?></td>
                                    <td><?= e($serviceReference !== '' ? $serviceReference : (string) ($service['line_reference'] ?? '')) ?></td>
                                    <td><?= e($passenger !== '' ? $passenger : $customerName) ?></td>
                                    <td><?= e($description) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </section>
                <?php endif; ?>

                <footer class="receipt-sheet__foot">
                    <div class="receipt-signatures">
                        <div>
                            <span>Received By</span>
                            <strong><?= e($receivedBy !== '' ? $receivedBy : 'Authorized Staff') ?></strong>
                        </div>
                        <div>
                            <span>Authorized By</span>
                            <strong><?= e((string) ($branchBranding['name'] ?? 'Travel Agency')) ?></strong>
                        </div>
                    </div>
                    <div class="receipt-sheet__note">Thank you for your business.</div>
                    <div class="receipt-sheet__note receipt-sheet__note--muted">This is a computer-generated receipt.</div>
                </footer>
            </section>
        <?php endif; ?>

        <?php if ($outputType === 'supplier_voucher' && $selectedSupplierPayment !== null): ?>
            <section class="output-block">
                <h2>Supplier Payment Voucher</h2>
                <div class="output-kv-grid">
                    <div><span>Voucher No.</span><strong><?= e((string) ($selectedSupplierPayment['paymentNo'] ?? '')) ?></strong></div>
                    <div><span>Voucher Date</span><strong><?= e((string) ($selectedSupplierPayment['paymentDate'] ?? '')) ?></strong></div>
                    <div><span>Supplier</span><strong><?= e((string) ($selectedSupplierPayment['supplier'] ?? '')) ?></strong></div>
                    <div><span>Currency</span><strong><?= e((string) ($selectedSupplierPayment['currency'] ?? '')) ?></strong></div>
                    <div><span>Amount Paid</span><strong><?= e($formatMoney((float) ($selectedSupplierPayment['paidAmount'] ?? 0))) ?></strong></div>
                    <div><span>Applied to Payables</span><strong><?= e($formatMoney((float) ($selectedSupplierPayment['allocatedAmount'] ?? 0))) ?></strong></div>
                    <div><span>Open Supplier Credit</span><strong><?= e($formatMoney((float) ($selectedSupplierPayment['unallocatedAmount'] ?? 0))) ?></strong></div>
                    <div><span>Payment Method</span><strong><?= e(ucwords(str_replace('_', ' ', (string) ($selectedSupplierPayment['paymentMethod'] ?? '')))) ?></strong></div>
                    <div><span>Reference No.</span><strong><?= e((string) (($selectedSupplierPayment['referenceNumber'] ?? '') !== '' ? $selectedSupplierPayment['referenceNumber'] : 'N/A')) ?></strong></div>
                    <div><span>Status</span><strong><?= e((string) ($selectedSupplierPayment['status'] ?? '')) ?></strong></div>
                    <?php if ((float) ($selectedSupplierPayment['exchangeRateToBooking'] ?? 0) > 0): ?>
                        <div><span>Exchange Rate</span><strong><?= e(number_format((float) $selectedSupplierPayment['exchangeRateToBooking'], 8)) ?></strong></div>
                    <?php endif; ?>
                </div>
            </section>
            <section class="output-block">
                <h2>Applied Supplier Payables</h2>
                <table class="output-table">
                    <thead><tr><th>Allocated At</th><th>Service Line</th><th>Currency</th><th>Allocated Amount</th><th>Note</th></tr></thead>
                    <tbody>
                    <?php $matchingSupplierAllocations = array_values(array_filter($supplierFoundation['paymentAllocations'] ?? [], static fn (array $allocation): bool => (string) ($allocation['paymentNo'] ?? '') === (string) ($selectedSupplierPayment['paymentNo'] ?? ''))); ?>
                    <?php if ($matchingSupplierAllocations === []): ?>
                        <tr><td colspan="5">No supplier payable has been linked to this payment yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($matchingSupplierAllocations as $allocation): ?>
                        <tr>
                            <td><?= e((string) ($allocation['allocatedAt'] ?? '')) ?></td>
                            <td><?= e((string) ($allocation['serviceLineReference'] ?? '')) ?></td>
                            <td><?= e((string) ($allocation['currency'] ?? '')) ?></td>
                            <td><?= e($formatMoney((float) ($allocation['allocatedAmount'] ?? 0))) ?></td>
                            <td><?= e((string) (($allocation['allocationNote'] ?? '') !== '' ? $allocation['allocationNote'] : 'N/A')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </section>
        <?php endif; ?>

        <?php if ($outputType === 'account_statement'): ?>
            <section class="output-block">
                <h2>Statement Summary</h2>
                <div class="output-summary-strip">
                    <div><span>Previous Balance</span><strong><?= e($statementPreviousBalanceDisplay) ?></strong></div>
                    <div><span>Current Invoice Amount</span><strong><?= e($formatCurrencyTotals($invoiceReceivableTotals)) ?></strong></div>
                    <div><span>Current Invoice Outstanding</span><strong><?= e($formatCurrencyTotals($invoiceOutstandingTotals)) ?></strong></div>
                    <div><span>Total Outstanding</span><strong><?= e($formatCurrencyTotals($statementTotalOutstandingTotals)) ?></strong></div>
                    <?php if ($statementOtherCurrencyPreviousTotals !== []): ?>
                        <div><span>Other Currency Outstanding</span><strong><?= e($formatCurrencyTotals($statementOtherCurrencyPreviousTotals)) ?></strong></div>
                    <?php endif; ?>
                    <div><span>Still Due</span><strong><?= e($formatCurrencyTotals($remainingCustomerBalanceTotals !== [] ? $remainingCustomerBalanceTotals : ['PKR' => 0])) ?></strong></div>
                    <div><span>Customer Credit</span><strong><?= e($formatCurrencyTotals($customerCreditTotals)) ?></strong></div>
                </div>
            </section>
            <section class="output-block">
                <h2>Invoice Exposure</h2>
                <table class="output-table">
                    <thead><tr><th>Service Line</th><th>Service</th><th>Currency</th><th>Invoice Amount</th><th>Applied</th><th>Outstanding</th><th>Status</th><th>Due Date</th></tr></thead>
                    <tbody>
                    <?php foreach (($customerPaymentFoundation['serviceReceivables'] ?? []) as $receivable): ?>
                        <tr>
                            <td><?= e((string) ($receivable['serviceLineReference'] ?? '')) ?></td>
                            <td><?= e(ucwords((string) ($receivable['serviceType'] ?? 'Service'))) ?></td>
                            <td><?= e((string) ($receivable['currency'] ?? '')) ?></td>
                            <td><?= e($formatMoney((float) ($receivable['dueAmount'] ?? 0))) ?></td>
                            <td><?= e($formatMoney((float) ($receivable['allocatedAmount'] ?? 0))) ?></td>
                            <td><?= e($formatMoney((float) ($receivable['outstandingAmount'] ?? 0))) ?></td>
                            <td><?= e((string) ($receivable['status'] ?? '')) ?></td>
                            <td><?= e((string) ($receivable['nextDueDate'] ?? '')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (($customerPaymentFoundation['serviceReceivables'] ?? []) === []): ?><tr><td colspan="8">No receivable entries are available for this booking yet.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </section>
            <section class="output-block">
                <h2>Receipt History</h2>
                <table class="output-table">
                    <thead><tr><th>Receipt</th><th>Date</th><th>Curr.</th><th>Received</th><th>Allocated</th><th>Unallocated</th><th>Method</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php if (($customerPaymentFoundation['receipts'] ?? []) === []): ?>
                        <tr><td colspan="8">No receipts recorded against this booking yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach (($customerPaymentFoundation['receipts'] ?? []) as $receipt): ?>
                        <tr>
                            <td><?= e((string) ($receipt['receiptNo'] ?? '')) ?></td>
                            <td><?= e((string) ($receipt['receiptDate'] ?? '')) ?></td>
                            <td><?= e((string) ($receipt['currency'] ?? '')) ?></td>
                            <td><?= e($formatMoney((float) ($receipt['receivedAmount'] ?? 0))) ?></td>
                            <td><?= e($formatMoney((float) ($receipt['allocatedAmount'] ?? 0))) ?></td>
                            <td><?= e($formatMoney((float) ($receipt['unallocatedAmount'] ?? 0))) ?></td>
                            <td><?= e(ucwords(str_replace('_', ' ', (string) ($receipt['paymentMethod'] ?? '')))) ?></td>
                            <td><?= e((string) ($receipt['status'] ?? '')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </section>
            <section class="output-block">
                <h2>Receipt Allocation Details</h2>
                <table class="output-table">
                    <thead><tr><th>Receipt</th><th>Allocated At</th><th>Allocation Type</th><th>Booking / Invoice No.</th><th>Service Line</th><th>Service Type</th><th>Passenger</th><th>Currency</th><th>Allocated</th><th>Current Remaining Balance</th></tr></thead>
                    <tbody>
                    <?php if (($customerPaymentFoundation['allocations'] ?? []) === []): ?>
                        <tr><td colspan="10">No receipt allocation rows are available for this booking yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach (($customerPaymentFoundation['allocations'] ?? []) as $allocation): ?>
                        <?php
                        $allocationCurrency = (string) ($allocation['receivableCurrency'] ?? $allocation['currency'] ?? 'PKR');
                        $allocationPassenger = trim((string) ($allocation['passengerName'] ?? ''));
                        ?>
                        <tr>
                            <td><?= e((string) ($allocation['receiptNo'] ?? '')) ?></td>
                            <td><?= e((string) ($allocation['allocatedAt'] ?? '')) ?></td>
                            <td><?= e((string) ($allocation['allocationType'] ?? 'Allocated')) ?></td>
                            <td><?= e((string) ($allocation['bookingReference'] ?? 'N/A')) ?></td>
                            <td><?= e((string) (($allocation['serviceLineReference'] ?? '') !== '' ? $allocation['serviceLineReference'] : 'N/A')) ?></td>
                            <td><?= e((string) ($allocation['serviceType'] ?? 'Service')) ?></td>
                            <td><?= e($allocationPassenger !== '' ? $allocationPassenger : $customerName) ?></td>
                            <td><?= e($allocationCurrency) ?></td>
                            <td><?= e($allocationCurrency) ?> <?= e($formatMoney((float) ($allocation['receivableAmountAllocated'] ?? $allocation['allocatedAmount'] ?? 0))) ?></td>
                            <td><?= e($allocationCurrency) ?> <?= e($formatMoney((float) ($allocation['currentOutstandingAmount'] ?? 0))) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </section>
        <?php endif; ?>

        <?php if ($outputType === 'itinerary'): ?>
            <section class="output-block">
                <h2>Passenger Details</h2>
                <table class="output-table">
                    <thead><tr><th>Role</th><th>Name</th><th>Passport</th><th>Nationality</th><th>DOB</th><th>Mobile</th></tr></thead>
                    <tbody>
                    <?php foreach ($travelers as $traveler): ?>
                        <tr>
                            <td><?= e(ucfirst((string) ($traveler['traveler_role'] ?? 'additional'))) ?></td>
                            <td><?= e((string) ($traveler['full_name'] ?? '')) ?></td>
                            <td><?= e((string) (($traveler['passport_number'] ?? '') !== '' ? $traveler['passport_number'] : 'N/A')) ?></td>
                            <td><?= e((string) (($traveler['nationality'] ?? '') !== '' ? $traveler['nationality'] : 'N/A')) ?></td>
                            <td><?= e((string) (($traveler['date_of_birth'] ?? '') !== '' ? $traveler['date_of_birth'] : 'N/A')) ?></td>
                            <td><?= e((string) (($traveler['mobile'] ?? '') !== '' ? $traveler['mobile'] : 'N/A')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($travelers === []): ?><tr><td colspan="6">No passenger details are linked to this booking yet.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </section>
            <section class="output-block">
                <h2>Travel Itinerary</h2>
                <table class="output-table">
                    <thead><tr><th>Line</th><th>Service</th><th>Passenger</th><th>Sector / Destination</th><th>Departure</th><th>Return</th><th>Reference</th></tr></thead>
                    <tbody>
                    <?php foreach ($services as $service): ?>
                        <tr>
                            <td><?= e((string) ($service['line_reference'] ?? '')) ?></td>
                            <td><?= e(ucwords((string) ($service['service_type'] ?? 'service'))) ?></td>
                            <td><?= e((string) (($service['passenger_name'] ?? '') !== '' ? $service['passenger_name'] : $customerName)) ?></td>
                            <td><?= e($cleanServiceDescription($service)) ?></td>
                            <td><?= e((string) (($service['departure_date'] ?? '') !== '' ? $service['departure_date'] : (($service['due_date'] ?? '') !== '' ? $service['due_date'] : 'N/A'))) ?></td>
                            <td><?= e((string) (($service['return_date'] ?? '') !== '' ? $service['return_date'] : (($booking['return_date'] ?? '') !== '' ? $booking['return_date'] : 'N/A'))) ?></td>
                            <td><?= e((string) (($service['ticket_number'] ?? '') !== '' ? $service['ticket_number'] : ($service['line_reference'] ?? 'N/A'))) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($services === []): ?><tr><td colspan="7">No travel services are linked to this booking yet.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </section>
        <?php endif; ?>

        <?php if ($outputType === 'booking_confirmation'): ?>
            <section class="output-block">
                <h2>Confirmed Services</h2>
                <table class="output-table">
                    <thead><tr><th>Line</th><th>Service</th><th>Passenger</th><th>Travel Window</th><th>Reference</th><th>Currency</th><th>Amount</th></tr></thead>
                    <tbody>
                    <?php foreach ($services as $service): ?>
                        <tr>
                            <td><?= e((string) ($service['line_reference'] ?? '')) ?></td>
                            <td><?= e(ucwords((string) ($service['service_type'] ?? 'service'))) ?></td>
                            <td><?= e((string) (($service['passenger_name'] ?? '') !== '' ? $service['passenger_name'] : $customerName)) ?></td>
                            <td><?php $window = trim((string) (($service['departure_date'] ?? $booking['departure_date'] ?? '') . (((string) ($service['return_date'] ?? $booking['return_date'] ?? '') !== '') ? ' to ' . (string) ($service['return_date'] ?? $booking['return_date'] ?? '') : ''))); echo e($window !== '' ? $window : 'As per booking file'); ?></td>
                            <td><?= e((string) (($service['ticket_number'] ?? '') !== '' ? $service['ticket_number'] : ($service['line_reference'] ?? 'N/A'))) ?></td>
                            <td><?= e((string) ($service['currency'] ?? 'PKR')) ?></td>
                            <td><?= e($formatMoney((float) (($service['sale_price'] ?? 0) + ($service['service_charge'] ?? 0) - ($service['discount_amount'] ?? 0)))) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($services === []): ?><tr><td colspan="7">No confirmed service lines are available for this booking yet.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </section>
            <section class="output-block">
                <h2>Booking Notes</h2>
                <div class="output-note-box">
                    <p>Please review passenger names, passport details, and travel dates before final handover.</p>
                    <p>Service references and travel windows shown here reflect the current saved booking record.</p>
                    <?php if (trim((string) ($booking['remarks'] ?? '')) !== ''): ?><p><?= e((string) $booking['remarks']) ?></p><?php endif; ?>
                </div>
            </section>
        <?php endif; ?>

        <?php if ($outputType !== 'customer_receipt'): ?>
            <footer class="output-foot">
                <div><?= e((string) ($branchBranding['name'] ?? 'Travel Agency')) ?> / <?= e((string) ($booking['booking_reference'] ?? '')) ?></div>
                <div><?= e($generatedAt) ?></div>
            </footer>
        <?php endif; ?>
    <?php if ($outputType !== 'customer_receipt'): ?>
        </section>
    <?php endif; ?>
</main>
