<?php

$booking = is_array($booking ?? null) ? $booking : [];
$travelers = is_array($travelers ?? null) ? $travelers : [];
$services = is_array($services ?? null) ? $services : [];
$customerPaymentFoundation = is_array($customerPaymentFoundation ?? null) ? $customerPaymentFoundation : [];
$supplierFoundation = is_array($supplierFoundation ?? null) ? $supplierFoundation : [];
$selectedReceipt = is_array($selectedReceipt ?? null) ? $selectedReceipt : null;
$selectedSupplierPayment = is_array($selectedSupplierPayment ?? null) ? $selectedSupplierPayment : null;
$selectedRefundEvent = is_array($selectedRefundEvent ?? null) ? $selectedRefundEvent : null;
$branchBranding = is_array($branchBranding ?? null) ? $branchBranding : [];
$summary = is_array($summary ?? null) ? $summary : [];
$outputType = (string) ($outputType ?? 'invoice');
$outputTypeLabel = (string) ($outputTypeLabel ?? 'Operational Output');
$generatedAt = (string) ($generatedAt ?? '');
$backUrl = (string) ($backUrl ?? url('/workspace'));
$showOutputDebug = app_debug_tools_enabled() && (string) ($_GET['debug_ui'] ?? '') === '1';

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
$formatStatusLabel = static function (?string $status): string {
    $normalized = str_replace(' ', '_', mb_strtolower(trim((string) $status)));
    if ($normalized === 'void') {
        return 'VOID';
    }

    return ucwords(str_replace('_', ' ', $normalized));
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
$selectedReceiptStatusRaw = str_replace(' ', '_', mb_strtolower(trim((string) ($selectedReceipt['statusRaw'] ?? $selectedReceipt['status'] ?? ''))));
$selectedReceiptIsVoid = $selectedReceiptStatusRaw === 'void';
$selectedReceiptVoidReason = trim((string) ($selectedReceipt['voidReason'] ?? ''));
$selectedReceiptVoidedAt = trim((string) ($selectedReceipt['voidedAt'] ?? ''));
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
$selectedSupplierPaymentStatusRaw = str_replace(' ', '_', mb_strtolower(trim((string) ($selectedSupplierPayment['statusRaw'] ?? $selectedSupplierPayment['status'] ?? ''))));
$selectedSupplierPaymentIsVoid = $selectedSupplierPaymentStatusRaw === 'void';
$selectedSupplierPaymentVoidReason = trim((string) ($selectedSupplierPayment['voidReason'] ?? ''));
$selectedSupplierPaymentVoidedAt = trim((string) ($selectedSupplierPayment['voidedAt'] ?? ''));
$invoiceServiceSummary = $serviceSummary;
$documentAudienceNote = match ($outputType) {
    'invoice' => 'Customer-facing invoice generated from the current saved booking services.',
    'account_statement' => 'Customer account statement for this booking and its saved receipts.',
    'itinerary' => 'Travel service summary for passenger and booking reference use.',
    'booking_confirmation' => 'Booking confirmation generated from the current saved booking record.',
    'service_refund_receipt' => 'Cancellation and refund receipt generated from the posted service refund event.',
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
$statementPreviousBalanceTotals = $nonZeroCurrencyTotals($customerPreviousBalanceTotals);
$statementCustomerCreditTotals = $nonZeroCurrencyTotals($customerCreditTotals);
$selectedReceiptId = (int) ($selectedReceipt['id'] ?? 0);
$selectedReceiptNo = (string) ($selectedReceipt['receiptNo'] ?? '');
$selectedRefundPayload = [];
if ($selectedRefundEvent !== null && ! empty($selectedRefundEvent['payload_json'])) {
    $decodedPayload = json_decode((string) $selectedRefundEvent['payload_json'], true);
    if (is_array($decodedPayload)) {
        $selectedRefundPayload = $decodedPayload;
    }
}
$selectedRefundPaymentMethod = ucwords(str_replace('_', ' ', (string) ($selectedRefundPayload['payment_method'] ?? 'cash')));
$selectedRefundService = null;
if ($selectedRefundEvent !== null) {
    $refundServiceId = (int) ($selectedRefundEvent['booking_service_id'] ?? 0);
    foreach ($services as $service) {
        if ((int) ($service['id'] ?? 0) === $refundServiceId) {
            $selectedRefundService = $service;
            break;
        }
    }
}
$receiptOriginalPaymentRowsByKey = [];

$registerReceiptOriginalPayment = static function (array $receiptRow) use (&$receiptOriginalPaymentRowsByKey): void {
    $receiptId = (int) ($receiptRow['id'] ?? $receiptRow['receiptId'] ?? 0);
    $receiptNo = trim((string) ($receiptRow['receiptNo'] ?? $receiptRow['receipt_no'] ?? ''));
    $currency = trim((string) ($receiptRow['currency'] ?? $receiptRow['paymentCurrency'] ?? ''));
    $receivedAmount = (float) ($receiptRow['receivedAmount'] ?? $receiptRow['received_amount'] ?? 0);

    if (($receiptId <= 0 && $receiptNo === '') || $currency === '' || abs($receivedAmount) <= 0.005) {
        return;
    }

    $payload = [
        'currency' => $currency,
        'receivedAmount' => $receivedAmount,
    ];

    if ($receiptId > 0) {
        $receiptOriginalPaymentRowsByKey['id:' . $receiptId] = $payload;
    }

    if ($receiptNo !== '') {
        $receiptOriginalPaymentRowsByKey['no:' . $receiptNo] = $payload;
    }
};

foreach (($customerPaymentFoundation['receipts'] ?? []) as $receiptRow) {
    $registerReceiptOriginalPayment($receiptRow);
}

if ($selectedReceipt !== null) {
    $registerReceiptOriginalPayment($selectedReceipt);
}

$getOriginalReceiptPaymentDisplay = static function (array $historyRow) use ($receiptOriginalPaymentRowsByKey, $receiptCurrency, $formatMoney): string {
    $historyReceiptId = (int) ($historyRow['receiptId'] ?? $historyRow['id'] ?? 0);
    $historyReceiptNo = trim((string) ($historyRow['receiptNo'] ?? $historyRow['receipt_no'] ?? ''));

    $originalPayment = null;

    if ($historyReceiptId > 0 && isset($receiptOriginalPaymentRowsByKey['id:' . $historyReceiptId])) {
        $originalPayment = $receiptOriginalPaymentRowsByKey['id:' . $historyReceiptId];
    } elseif ($historyReceiptNo !== '' && isset($receiptOriginalPaymentRowsByKey['no:' . $historyReceiptNo])) {
        $originalPayment = $receiptOriginalPaymentRowsByKey['no:' . $historyReceiptNo];
    }

    if (is_array($originalPayment)) {
        return (string) $originalPayment['currency'] . ' ' . $formatMoney((float) $originalPayment['receivedAmount']);
    }

    $fallbackCurrency = (string) ($historyRow['paymentCurrency'] ?? $historyRow['currency'] ?? $receiptCurrency);
    $fallbackAmount = (float) (
        $historyRow['paymentAmountConsumed']
        ?? $historyRow['receivedAmount']
        ?? $historyRow['allocatedAmount']
        ?? $historyRow['receivableAmountAllocated']
        ?? 0
    );

    return $fallbackCurrency . ' ' . $formatMoney($fallbackAmount);
};
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
$previousBalancePaidByReceiptTotals = [];
$previousBalanceAllocationRows = [];

foreach ($receiptAllocations as $allocation) {
    $allocationBookingReference = (string) ($allocation['bookingReference'] ?? '');
    if ($allocationBookingReference === '' || $allocationBookingReference === $bookingReference) {
        continue;
    }

    $currency = (string) ($allocation['receivableCurrency'] ?? $allocation['currency'] ?? $receiptCurrency);
    if ($currency === '') {
        continue;
    }

    $amount = (float) ($allocation['receivableAmountAllocated'] ?? $allocation['allocatedAmount'] ?? 0);
    if (abs($amount) <= 0.005) {
        continue;
    }

    $previousBalancePaidByReceiptTotals[$currency] = ($previousBalancePaidByReceiptTotals[$currency] ?? 0.0) + $amount;
    $previousBalanceAllocationRows[] = $allocation;
}

$previousBalancePaidByReceiptDisplay = $nonZeroCurrencyTotals($previousBalancePaidByReceiptTotals);

$previousBalanceBeforeReceiptTotals = $remainingCustomerBalanceTotals;
foreach ($previousBalancePaidByReceiptTotals as $currency => $amount) {
    $previousBalanceBeforeReceiptTotals[$currency] = round(
        (float) ($previousBalanceBeforeReceiptTotals[$currency] ?? 0) + (float) $amount,
        2
    );
}
$previousBalanceBeforeReceiptDisplay = $nonZeroCurrencyTotals($previousBalanceBeforeReceiptTotals);
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
    static function (array $allocation) use ($bookingReference): bool {
        if ((string) ($allocation['bookingReference'] ?? '') !== $bookingReference) {
            return false;
        }

        $receiptStatusRaw = str_replace(' ', '_', mb_strtolower(trim((string) ($allocation['receiptStatusRaw'] ?? ''))));

        return $receiptStatusRaw !== 'void';
    }
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
$openSupplierHistoryUrl = null;
if ($outputType === 'supplier_voucher' && (int) ($booking['id'] ?? 0) > 0) {
    $openSupplierHistoryUrl = url('/workspace?booking_id=' . (int) $booking['id'] . '#dock-panel-suppliers');
}
?>
<main class="output-page">
    <div class="output-toolbar no-print">
        <a class="btn btn-sm" href="<?= e($backUrl) ?>">Back to Workspace</a>
        <?php if ($openSupplierHistoryUrl !== null): ?>
            <a class="btn btn-sm" href="<?= e($openSupplierHistoryUrl) ?>">Open Supplier History</a>
        <?php endif; ?>
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

        <?php if ($outputType === 'booking_summary_receipt'): ?>
            <?php
            $bookingSummaryPaymentRows = [];

            foreach ($invoicePaymentHistoryRows as $historyRow) {
                $receiptNo = trim((string) ($historyRow['receiptNo'] ?? ''));
                $receiptId = (int) ($historyRow['receiptId'] ?? 0);
                $receiptKey = $receiptId > 0 ? 'id:' . $receiptId : 'no:' . $receiptNo;

                if ($receiptKey === 'no:') {
                    $receiptKey = 'row:' . count($bookingSummaryPaymentRows);
                }

                if (!isset($bookingSummaryPaymentRows[$receiptKey])) {
                    $bookingSummaryPaymentRows[$receiptKey] = [
                        'receiptNo' => $receiptNo,
                        'allocatedAt' => (string) ($historyRow['allocatedAt'] ?? ''),
                        'status' => (string) ($historyRow['receiptStatusRaw'] ?? $historyRow['receiptStatus'] ?? 'posted'),
                        'totals' => [],
                    ];
                }

                $currency = (string) ($historyRow['receivableCurrency'] ?? $historyRow['currency'] ?? 'PKR');
                $amount = (float) ($historyRow['receivableAmountAllocated'] ?? $historyRow['allocatedAmount'] ?? 0);

                if ($currency !== '' && abs($amount) > 0.005) {
                    $bookingSummaryPaymentRows[$receiptKey]['totals'][$currency] =
                        (float) ($bookingSummaryPaymentRows[$receiptKey]['totals'][$currency] ?? 0) + $amount;
                }
            }
            ?>

            <section class="output-block">
                <h2>Booking Summary Receipt</h2>
                <div class="output-summary-strip">
                    <div><span>Booking / Invoice No.</span><strong><?= e($bookingReference) ?></strong></div>
                    <div><span>Customer</span><strong><?= e($customerName) ?></strong></div>
                    <div><span>Total Invoice Amount</span><strong><?= e($formatCurrencyTotals($invoiceReceivableTotals !== [] ? $invoiceReceivableTotals : ['PKR' => 0])) ?></strong></div>
                    <div><span>Total Paid</span><strong><?= e($formatCurrencyTotals($invoiceReceivedTotals !== [] ? $invoiceReceivedTotals : ['PKR' => 0])) ?></strong></div>
                    <div><span>Balance Due</span><strong><?= e($formatCurrencyTotals($invoiceOutstandingTotals !== [] ? $invoiceOutstandingTotals : ['PKR' => 0])) ?></strong></div>
                    <?php if ($bookingDueDate !== ''): ?>
                        <div><span>Due Date</span><strong><?= e($bookingDueDate) ?></strong></div>
                    <?php endif; ?>
                </div>
            </section>

            <section class="output-block">
                <h2>Services Included in This Booking</h2>
                <table class="output-table">
                    <thead>
                    <tr>
                        <th>Line</th>
                        <th>Service</th>
                        <th>Passenger</th>
                        <th>Ticket / Ref.</th>
                        <th>Detail</th>
                        <th>Currency</th>
                        <th>Amount</th>
                        <th>Status</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($services as $service): ?>
                        <?php
                        $summaryServiceReference = trim((string) (($service['ticket_number'] ?? '') !== '' ? $service['ticket_number'] : ($service['line_reference'] ?? '')));
                        $summaryPassenger = trim((string) ($service['passenger_name'] ?? ''));
                        $summaryFinalSale = (float) ($service['final_sale_price'] ?? 0);
                        $summaryServiceAmount = abs($summaryFinalSale) > 0.005
                            ? $summaryFinalSale
                            : (float) ($service['sale_price'] ?? 0)
                                + (float) ($service['service_charge'] ?? 0)
                                - (float) ($service['discount_amount'] ?? 0);
                        ?>
                        <tr>
                            <td><?= e((string) ($service['line_reference'] ?? '')) ?></td>
                            <td><?= e(ucwords((string) ($service['service_type'] ?? 'service'))) ?></td>
                            <td><?= e($summaryPassenger !== '' ? $summaryPassenger : $customerName) ?></td>
                            <td><?= e($summaryServiceReference !== '' ? $summaryServiceReference : 'N/A') ?></td>
                            <td><?= e($cleanServiceDescription($service)) ?></td>
                            <td><?= e((string) ($service['currency'] ?? 'PKR')) ?></td>
                            <td><?= e($formatMoney($summaryServiceAmount)) ?></td>
                            <td><?= e(ucwords((string) ($service['status'] ?? 'Open'))) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($services === []): ?>
                        <tr><td colspan="8">No service lines recorded for this booking yet.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </section>

            <section class="output-block">
                <h2>Payments Applied to This Booking</h2>
                <table class="output-table">
                    <thead>
                    <tr>
                        <th>Receipt No.</th>
                        <th>Applied At</th>
                        <th>Amount Applied</th>
                        <th>Status</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if ($bookingSummaryPaymentRows === []): ?>
                        <tr><td colspan="4">No customer payment has been applied to this booking yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($bookingSummaryPaymentRows as $paymentRow): ?>
                        <tr>
                            <td><?= e((string) ($paymentRow['receiptNo'] ?? '')) ?></td>
                            <td><?= e((string) ($paymentRow['allocatedAt'] ?? '')) ?></td>
                            <td><?= e($formatCurrencyTotals($paymentRow['totals'] ?? [])) ?></td>
                            <td><?= e($formatStatusLabel((string) ($paymentRow['status'] ?? 'posted'))) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
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
                        <div class="receipt-sheet__title">
                            Customer Payment Receipt
                            <?php if ($selectedReceiptIsVoid): ?>
                                <span style="display:inline-block;margin-left:10px;padding:4px 10px;border:1px solid #8b0000;border-radius:999px;background:#fff0f0;color:#8b0000;font-size:12px;font-weight:700;letter-spacing:0.08em;">VOID</span>
                            <?php endif; ?>
                        </div>
                        <div class="receipt-sheet__subtitle">Payment acknowledgment for travel services</div>
                    </div>
                </header>

                <?php if ($selectedReceiptIsVoid): ?>
                    <section class="receipt-card" style="border-color:#8b0000;background:#fff7f7;">
                        <div class="receipt-card__grid">
                            <div><span>Status</span><strong>VOID</strong></div>
                            <?php if ($selectedReceiptVoidedAt !== ''): ?>
                                <div><span>Voided At</span><strong><?= e($selectedReceiptVoidedAt) ?></strong></div>
                            <?php endif; ?>
                            <?php if ($selectedReceiptVoidReason !== ''): ?>
                                <div style="grid-column:1 / -1;"><span>Void Reason</span><strong><?= e($selectedReceiptVoidReason) ?></strong></div>
                            <?php endif; ?>
                        </div>
                    </section>
                <?php endif; ?>

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
   
    <?php if ($previousBalancePaidByReceiptDisplay !== []): ?>
        <?php if ($previousBalancePaidByReceiptDisplay !== []): ?>
    <div>
        <span>Paid Against Previous Balance</span>
        <strong><?= e($formatCurrencyTotals($previousBalancePaidByReceiptDisplay)) ?></strong>
    </div>
<?php endif; ?>
    <?php endif; ?>
    <div>
        <span>Credit / Return TO CUSTOMER</span>
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
                                <span>Current Invoice Payment</span>
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
<?php if ($dueDateForReceipt !== '' && $currentInvoiceBalanceDisplay !== []): ?>
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
                    <?php
                    $receiptPaymentHistoryRows = [];

                    foreach ($invoicePaymentHistoryRows as $historyRow) {
                        $receiptNo = trim((string) ($historyRow['receiptNo'] ?? ''));
                        $receiptId = (int) ($historyRow['receiptId'] ?? 0);
                        $historyKey = $receiptId > 0 ? 'id:' . $receiptId : 'no:' . $receiptNo;

                        if ($historyKey === 'no:') {
                            $historyKey = 'row:' . count($receiptPaymentHistoryRows);
                        }

                        if (!isset($receiptPaymentHistoryRows[$historyKey])) {
                            $receiptPaymentHistoryRows[$historyKey] = [
                                'receiptNo' => $receiptNo,
                                'receiptDate' => (string) ($historyRow['receiptDate'] ?? ''),
                                'paymentReceivedDisplay' => $getOriginalReceiptPaymentDisplay($historyRow),
                                'currencyTotals' => [],
                                'isCurrentReceipt' => false,
                            ];
                        }

                        $historyCurrency = (string) ($historyRow['receivableCurrency'] ?? $historyRow['currency'] ?? $receiptCurrency);
                        $historyAmount = (float) ($historyRow['receivableAmountAllocated'] ?? $historyRow['allocatedAmount'] ?? 0);

                        if ($historyCurrency !== '' && abs($historyAmount) > 0.005) {
                            $receiptPaymentHistoryRows[$historyKey]['currencyTotals'][$historyCurrency] =
                                (float) ($receiptPaymentHistoryRows[$historyKey]['currencyTotals'][$historyCurrency] ?? 0) + $historyAmount;
                        }

                        if ($isSelectedReceiptHistoryRow($historyRow)) {
                            $receiptPaymentHistoryRows[$historyKey]['isCurrentReceipt'] = true;
                        }
                    }
                    ?>

                    <h2 style="margin:0 0 12px;">Invoice Payment History</h2>
                    <table class="output-table receipt-table">
                        <thead>
                        <tr>
                            <th>Receipt No.</th>
                            <th>Receipt Date</th>
                            <th>Payment Received</th>
                            <th>Invoice Payment</th>
                            <th>Current Receipt</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if ($receiptPaymentHistoryRows === []): ?>
                            <tr><td colspan="5">No payment history is available for this invoice yet.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($receiptPaymentHistoryRows as $historyRow): ?>
                            <tr>
                                <td><?= e((string) ($historyRow['receiptNo'] ?? '')) ?></td>
                                <td><?= e((string) ($historyRow['receiptDate'] ?? '')) ?></td>
                                <td><?= e((string) ($historyRow['paymentReceivedDisplay'] ?? '')) ?></td>
                                <td><?= e($formatCurrencyTotals($historyRow['currencyTotals'] ?? [])) ?></td>
                                <td><?= e(($historyRow['isCurrentReceipt'] ?? false) ? 'Current Receipt' : 'Previous Payment') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </section>

                                                <section class="receipt-card">
                    <h2 style="margin:0 0 12px;">Service Details</h2>
                    <table class="output-table receipt-table">
                        <thead>
                        <tr>
                            <th>#</th>
                            <th>Service Ref.</th>
                            <th>Service</th>
                            <th>Passenger</th>
                            <th>Currency</th>
                            <th>Service Amount</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if ($services === []): ?>
                            <tr><td colspan="6">No service lines recorded for this booking yet.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($services as $serviceIndex => $service): ?>
                            <?php
                            $lineReference = trim((string) ($service['line_reference'] ?? ''));
                            $serviceCurrency = (string) ($service['currency'] ?? $receiptCurrency);
                            $servicePassenger = trim((string) ($service['passenger_name'] ?? ''));
                            $serviceFinalSale = (float) ($service['final_sale_price'] ?? 0);
                            $serviceAmount = abs($serviceFinalSale) > 0.005
                                ? $serviceFinalSale
                                : (float) ($service['sale_price'] ?? 0)
                                    + (float) ($service['service_charge'] ?? 0)
                                    - (float) ($service['discount_amount'] ?? 0);
                            ?>
                            <tr>
                                <td><?= e((string) ($serviceIndex + 1)) ?></td>
                                <td><?= e($lineReference !== '' ? $lineReference : 'N/A') ?></td>
                                <td><?= e(ucwords((string) ($service['service_type'] ?? 'Service'))) ?></td>
                                <td><?= e($servicePassenger !== '' ? $servicePassenger : $customerName) ?></td>
                                <td><?= e($serviceCurrency) ?></td>
                                <td><?= e($formatMoney($serviceAmount)) ?></td>
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

        <?php if ($outputType === 'service_refund_receipt' && $selectedRefundEvent !== null): ?>
            <section class="receipt-sheet">
                <header class="receipt-sheet__head">
                    <div>
                        <div class="receipt-sheet__branch"><?= e((string) ($branchBranding['name'] ?? 'Travel Agency Branch')) ?></div>
                        <div class="receipt-sheet__meta"><?= e(trim((string) (($branchBranding['city'] ?? '') . ((string) ($branchBranding['country'] ?? '') !== '' ? ', ' . (string) ($branchBranding['country'] ?? '') : '')))) ?></div>
                        <div class="receipt-sheet__meta"><?= e((string) ($branchBranding['tagline'] ?? 'Travel Agency Operations')) ?></div>
                    </div>
                    <div class="receipt-sheet__title-wrap">
                        <div class="receipt-sheet__title">Cancellation / Refund Receipt</div>
                        <div class="receipt-sheet__subtitle">Cancelled service and refunded amount confirmation</div>
                    </div>
                </header>

                <section class="receipt-card">
                    <div class="receipt-card__grid">
                        <div><span>Booking / Invoice No.</span><strong><?= e((string) ($booking['booking_reference'] ?? '')) ?></strong></div>
                        <div><span>Refund Date</span><strong><?= e((string) ($selectedRefundEvent['event_date'] ?? '')) ?></strong></div>
                        <div><span>Customer Name</span><strong><?= e($customerName) ?></strong></div>
                        <div><span>Service Line</span><strong><?= e((string) ($selectedRefundEvent['service_line_reference'] ?? 'N/A')) ?></strong></div>
                        <div><span>Service Status</span><strong>Cancelled</strong></div>
                        <div><span>Payment Method</span><strong><?= e($selectedRefundPaymentMethod) ?></strong></div>
                        <?php if ($selectedRefundService !== null): ?>
                            <div><span>Service Type</span><strong><?= e(ucwords((string) ($selectedRefundService['service_type'] ?? 'service'))) ?></strong></div>
                            <div><span>Service Detail</span><strong><?= e($cleanServiceDescription($selectedRefundService)) ?></strong></div>
                            <?php $refundServiceReference = trim((string) (($selectedRefundService['ticket_number'] ?? '') !== '' ? $selectedRefundService['ticket_number'] : ($selectedRefundService['line_reference'] ?? ''))); ?>
                            <?php if ($refundServiceReference !== ''): ?>
                                <div><span>Ticket / Reference No.</span><strong><?= e($refundServiceReference) ?></strong></div>
                            <?php endif; ?>
                            <?php if (trim((string) ($selectedRefundService['pnr'] ?? '')) !== ''): ?>
                                <div><span>PNR</span><strong><?= e((string) $selectedRefundService['pnr']) ?></strong></div>
                            <?php endif; ?>
                            <?php if (trim((string) ($selectedRefundService['airline'] ?? '')) !== ''): ?>
                                <div><span>Airline / Supplier</span><strong><?= e((string) $selectedRefundService['airline']) ?></strong></div>
                            <?php endif; ?>
                        <?php endif; ?>
                        <div style="grid-column:1 / -1;"><span>Refund Reason</span><strong><?= e((string) ($selectedRefundEvent['reason'] ?? '')) ?></strong></div>
                    </div>
                </section>

                <section class="receipt-card receipt-card--finance">
                    <div class="receipt-finance-strip">
                        <div>
                            <span>Customer Refund Paid</span>
                            <strong><?= e((string) ($selectedRefundEvent['currency'] ?? 'PKR')) ?> <?= e($formatMoney((float) ($selectedRefundEvent['customer_refund_amount'] ?? 0))) ?></strong>
                        </div>
                    </div>
                </section>

                <section class="receipt-card">
                    <div class="receipt-card__grid">
                        <div><span>Document Type</span><strong>Service Refund</strong></div>
                        <div><span>Generated At</span><strong><?= e($generatedAt) ?></strong></div>
                        <div><span>Prepared By</span><strong><?= e($receivedBy) ?></strong></div>
                    </div>
                </section>
            </section>
        <?php endif; ?>

        <?php if ($outputType === 'supplier_voucher' && $selectedSupplierPayment !== null): ?>
            <section class="output-block">
                <h2>
                    Supplier Payment Voucher
                    <?php if ($selectedSupplierPaymentIsVoid): ?>
                        <span style="display:inline-block;margin-left:10px;padding:4px 10px;border:1px solid #8b0000;border-radius:999px;background:#fff0f0;color:#8b0000;font-size:12px;font-weight:700;letter-spacing:0.08em;vertical-align:middle;">VOID</span>
                    <?php endif; ?>
                </h2>
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
                    <div><span>Status</span><strong><?= e($formatStatusLabel((string) ($selectedSupplierPayment['statusRaw'] ?? $selectedSupplierPayment['status'] ?? ''))) ?></strong></div>
                    <?php if ((float) ($selectedSupplierPayment['exchangeRateToBooking'] ?? 0) > 0): ?>
                        <div><span>Exchange Rate</span><strong><?= e(number_format((float) $selectedSupplierPayment['exchangeRateToBooking'], 8)) ?></strong></div>
                    <?php endif; ?>
                </div>
                <?php if ($selectedSupplierPaymentIsVoid): ?>
                    <div class="output-kv-grid" style="margin-top:14px;padding:14px;border:1px solid #8b0000;background:#fff7f7;">
                        <div><span>Status</span><strong>VOID</strong></div>
                        <?php if ($selectedSupplierPaymentVoidedAt !== ''): ?>
                            <div><span>Voided At</span><strong><?= e($selectedSupplierPaymentVoidedAt) ?></strong></div>
                        <?php endif; ?>
                        <?php if ($selectedSupplierPaymentVoidReason !== ''): ?>
                            <div style="grid-column:1 / -1;"><span>Void Reason</span><strong><?= e($selectedSupplierPaymentVoidReason) ?></strong></div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
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
                    <div><span>Invoice Amount</span><strong><?= e($formatCurrencyTotals($invoiceReceivableTotals)) ?></strong></div>
                    <div><span>Payments Received</span><strong><?= e($formatCurrencyTotals($invoiceReceivedTotals)) ?></strong></div>
                    <?php if ($statementPreviousBalanceTotals !== []): ?>
                        <div><span>Previous Balance</span><strong><?= e($formatCurrencyTotals($statementPreviousBalanceTotals)) ?></strong></div>
                    <?php endif; ?>
                    <div><span>Balance Due</span><strong><?= e($formatCurrencyTotals($remainingCustomerBalanceTotals !== [] ? $remainingCustomerBalanceTotals : ['PKR' => 0])) ?></strong></div>
                    <?php if ($statementCustomerCreditTotals !== []): ?>
                        <div><span>Customer Credit</span><strong><?= e($formatCurrencyTotals($statementCustomerCreditTotals)) ?></strong></div>
                    <?php endif; ?>
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
                            <td><?= e($formatStatusLabel((string) ($receipt['statusRaw'] ?? $receipt['status'] ?? ''))) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </section>
            <section class="output-block">
                <h2>Receipt Allocation Details</h2>
                <table class="output-table">
                    <thead><tr><th>Receipt</th><th>Allocated At</th><th>Allocation Type</th><th>Booking / Invoice No.</th><th>Service Line</th><th>Service Type</th><th>Passenger</th><th>Currency</th><th>Allocated</th><th>Remaining After This Allocation</th></tr></thead>
                    <tbody>
                    <?php if (($customerPaymentFoundation['allocations'] ?? []) === []): ?>
                        <tr><td colspan="10">No receipt allocation rows are available for this booking yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach (($customerPaymentFoundation['allocations'] ?? []) as $allocation): ?>
                        <?php
                        $allocationCurrency = (string) ($allocation['receivableCurrency'] ?? $allocation['currency'] ?? 'PKR');
                        $allocationPassenger = trim((string) ($allocation['passengerName'] ?? ''));
                        $allocationReceiptStatusRaw = str_replace(' ', '_', mb_strtolower(trim((string) ($allocation['receiptStatusRaw'] ?? ''))));
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
                            <td><?php if ($allocationReceiptStatusRaw === 'void'): ?>VOIDED<?php else: ?><?= e($allocationCurrency) ?> <?= e($formatMoney((float) ($allocation['remainingAfterAllocation'] ?? 0))) ?><?php endif; ?></td>
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
