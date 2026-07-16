<?php

$requestedServiceId = (int) ($requestedServiceId ?? 0);
$activeServiceIndex = 0;
if ($requestedServiceId > 0) {
    foreach ($serviceLines as $serviceLineIndex => $serviceLine) {
        if ((int) ($serviceLine['serviceId'] ?? 0) === $requestedServiceId) {
            $activeServiceIndex = (int) $serviceLineIndex;
            break;
        }
    }
}
$activeService = $serviceLines[$activeServiceIndex] ?? ($serviceLines[0] ?? []);
$passengerSummaryRows = is_array($passengerSummaryRows ?? null) ? $passengerSummaryRows : [];
if ($passengerSummaryRows === []) {
    foreach ($serviceLines as $serviceLine) {
        $passengerName = trim((string) ($serviceLine['passengerName'] ?? ''));
        if ($passengerName === '') {
            continue;
        }

        $route = trim((string) ($serviceLine['sectorFrom'] ?? ''));
        $sectorTo = trim((string) ($serviceLine['sectorTo'] ?? ''));
        if ($route !== '' && $sectorTo !== '') {
            $route .= ' / ' . $sectorTo;
        } elseif ($route === '' && $sectorTo !== '') {
            $route = $sectorTo;
        }
        if ($route === '') {
            $route = 'N/A';
        }

        $lineReceivable = round((float) ($serviceLine['rowReceivable'] ?? $serviceLine['finalSalePrice'] ?? 0), 2);
        if (
            ! array_key_exists('outstandingAmount', $serviceLine)
            && (bool) ($serviceLine['latestCancelFinanciallySettled'] ?? false)
        ) {
            $lineReceivable = round(max((float) ($serviceLine['latestCancelCustomerFinalChargeAmount'] ?? 0), 0), 2);
        }
        $linePaid = round((float) ($serviceLine['allocatedAmount'] ?? 0), 2);
        if (
            (bool) ($serviceLine['latestCancelFinanciallySettled'] ?? false)
            && $linePaid <= 0.005
            && $lineReceivable > 0.005
            && ! array_key_exists('outstandingAmount', $serviceLine)
        ) {
            $linePaid = $lineReceivable;
        }
        $lineOutstanding = array_key_exists('outstandingAmount', $serviceLine)
            ? round((float) ($serviceLine['outstandingAmount'] ?? 0), 2)
            : max(0, round($lineReceivable - $linePaid, 2));
        $linePaid = max(0, min($linePaid, $lineReceivable));
        $currency = (string) ($serviceLine['currency'] ?? 'PKR');

        $passengerSummaryRows[] = [
            'passengerName' => $passengerName,
            'relation' => count($passengerSummaryRows) === 0 ? 'Self' : 'Passenger',
            'pnr' => trim((string) ($serviceLine['pnr'] ?? '')) !== '' ? trim((string) ($serviceLine['pnr'] ?? '')) : 'N/A',
            'route' => $route,
            'invoiceAmountDisplay' => $currency . ' ' . $formatMoney($lineReceivable),
            'paidDisplay' => $currency . ' ' . $formatMoney($linePaid),
            'outstandingDisplay' => $currency . ' ' . $formatMoney($lineOutstanding),
            'remarks' => count($passengerSummaryRows) === 0 ? 'Lead Traveler' : '',
        ];
    }
}
$receiptRecreateDraft = is_array($receiptRecreateDraft ?? null) ? $receiptRecreateDraft : null;
$supplierPaymentRecreateDraft = is_array($supplierPaymentRecreateDraft ?? null) ? $supplierPaymentRecreateDraft : null;
$compactBranchName = static function (string $name): string {
    $normalized = mb_strtolower(trim($name));
    if (str_contains($normalized, 'imdad')) {
        return 'Imdad Int.';
    }
    if (str_contains($normalized, 'noble')) {
        return 'Noble Route';
    }

    return $name;
};
$formatStatusLabel = static function (?string $status): string {
    $normalized = str_replace(' ', '_', mb_strtolower(trim((string) $status)));
    if ($normalized === 'void') {
        return 'VOID';
    }

    return ucwords(str_replace('_', ' ', $normalized));
};
$invoiceDueDate = trim((string) ($workspaceBooking['dueDate'] ?? ''));
if ($invoiceDueDate === '') {
    foreach (($customerPaymentFoundation['openReceivables'] ?? []) as $receivableRow) {
        if (! empty($receivableRow['nextDueDate'])) {
            $invoiceDueDate = (string) $receivableRow['nextDueDate'];
            break;
        }
    }
}
if ($invoiceDueDate === '') {
    $invoiceDueDate = (string) ($activeService['dueDate'] ?? '');
}

$currentBookingReceiptIds = [];
foreach (($customerPaymentFoundation['allocations'] ?? []) as $allocationRow) {
    if ((string) ($allocationRow['bookingReference'] ?? '') !== (string) ($workspaceBooking['number'] ?? '')) {
        continue;
    }

    $receiptStatusRaw = str_replace(' ', '_', mb_strtolower(trim((string) ($allocationRow['receiptStatusRaw'] ?? ''))));
    if ($receiptStatusRaw === 'void') {
        continue;
    }

    $receiptId = (int) ($allocationRow['receiptId'] ?? 0);
    $allocatedAmount = (float) ($allocationRow['receivableAmountAllocated'] ?? $allocationRow['allocatedAmount'] ?? 0);
    if ($receiptId > 0 && abs($allocatedAmount) > 0.005) {
        $currentBookingReceiptIds[$receiptId] = true;
    }
}
$latestReceipt = null;
foreach (($customerPaymentFoundation['receipts'] ?? []) as $receiptCandidate) {
    $receiptId = (int) ($receiptCandidate['id'] ?? 0);
    if ($receiptId > 0 && isset($currentBookingReceiptIds[$receiptId])) {
        $latestReceipt = $receiptCandidate;
        break;
    }
}
$latestReceiptId = is_array($latestReceipt) ? (int) ($latestReceipt['id'] ?? 0) : 0;
$receiptNo = is_array($latestReceipt) ? (string) ($latestReceipt['receiptNo'] ?? '0') : '0';
$latestReceiptUrl = $latestReceiptId > 0 && (int) ($workspaceBooking['id'] ?? 0) > 0
    ? url('/workspace/output?booking_id=' . (int) $workspaceBooking['id'] . '&doc=customer_receipt&receipt_id=' . $latestReceiptId)
    : '';
$invoiceCurrency = (string) ($activeService['currency'] ?? '');
if ($invoiceCurrency === '') {
    $invoiceCurrency = (string) array_key_first($receivableTotals ?? []);
}
if ($invoiceCurrency === '') {
    $invoiceCurrency = (string) array_key_first($outstandingTotals ?? []);
}
if ($invoiceCurrency === '') {
    $invoiceCurrency = (string) array_key_first($receivedTotals ?? []);
}
if ($invoiceCurrency === '') {
    $invoiceCurrency = (string) ($workspaceBooking['defaultCurrency'] ?? '');
}
if ($invoiceCurrency === '') {
    $invoiceCurrency = 'PKR';
}
$globalPrepaidDefaultBranchId = (int) ($workspaceBooking['branchId'] ?? 0);
if ($globalPrepaidDefaultBranchId <= 0) {
    $globalPrepaidDefaultBranchId = (int) ($accessibleBranchIds[0] ?? ($branchOptions[0]['id'] ?? 0));
}
$globalCustomerPaymentUrl = url('/customers/settlements/global')
    . ($globalPrepaidDefaultBranchId > 0 ? '?' . http_build_query(['branch_id' => $globalPrepaidDefaultBranchId]) : '');
$globalSupplierPaymentUrl = url('/suppliers/settlements/global')
    . ($globalPrepaidDefaultBranchId > 0 ? '?' . http_build_query(['branch_id' => $globalPrepaidDefaultBranchId]) : '');
$supplierPaymentFinderPrintUrl = url('/reports/print?' . http_build_query(array_filter([
    'report' => 'supplier_outstanding',
    'branch_id' => $globalPrepaidDefaultBranchId > 0 ? $globalPrepaidDefaultBranchId : null,
], static fn ($value): bool => $value !== null && $value !== '')));
$clientCode = (int) ($leadTraveler['travelerId'] ?? 0) > 0 ? (string) $leadTraveler['travelerId'] : '-';
$familyId = trim((string) ($leadTraveler['familyId'] ?? ''));
$familyId = $familyId !== '' ? $familyId : '-';
$customerColorTag = trim((string) ($leadTraveler['colorTag'] ?? ''));
$customerColorTag = $customerColorTag !== '' ? ucwords(str_replace('_', ' ', $customerColorTag)) : '-';
$previousBalanceTotals = is_array($customerPaymentFoundation['summary']['previousBalance'] ?? null)
    ? $customerPaymentFoundation['summary']['previousBalance']
    : [];
$visiblePreviousBalanceTotals = array_filter(
    $previousBalanceTotals,
    static fn (float $amount): bool => abs($amount) > 0.005
);
$sameCurrencyPreviousBalanceAmount = (float) ($previousBalanceTotals[$invoiceCurrency] ?? 0.0);
$activeServiceSalePrice = (float) ($activeService['salePrice'] ?? 0);
$activeServiceCostCurrency = (string) ($activeService['costCurrency'] ?? $activeService['currency'] ?? $invoiceCurrency);
$activeServicePricingExchangeRate = (float) ($activeService['pricingExchangeRate'] ?? 1);
$activeServiceConvertedPurchaseCost = round(
    (float) ($activeService['purchaseCost'] ?? 0)
    * (((string) ($activeService['currency'] ?? $invoiceCurrency) === $activeServiceCostCurrency) ? 1 : max($activeServicePricingExchangeRate, 0)),
    2
);
$activeServiceFinalSalePrice = (float) ($activeService['finalSalePrice'] ?? 0);
$activeServiceAutoFinalSalePrice = (($activeService['type'] ?? 'air ticket') === 'air ticket'
    ? $activeServiceConvertedPurchaseCost
    : $activeServiceSalePrice)
    + (float) ($activeService['serviceCharge'] ?? 0)
    + (float) ($activeService['vat'] ?? 0)
    - (float) ($activeService['discountAmount'] ?? 0);
if (abs($activeServiceFinalSalePrice) <= 0.005 && (abs($activeServiceAutoFinalSalePrice) > 0.005)) {
    $activeServiceFinalSalePrice = round(
        $activeServiceAutoFinalSalePrice,
        2
    );
}
$activeServiceHasManualFinalSaleOverride = abs((float) ($activeService['finalSalePrice'] ?? 0) - round($activeServiceAutoFinalSalePrice, 2)) > 0.005;
$activeServiceManualSaleAdjustment = $activeServiceHasManualFinalSaleOverride
    ? round($activeServiceFinalSalePrice - $activeServiceAutoFinalSalePrice, 0)
    : 0.0;
$isPaymentEligibleServiceLine = static function (array $row): bool {
    $status = str_replace(' ', '_', mb_strtolower(trim((string) (
        $row['status']
        ?? $row['displayStatus']
        ?? $row['serviceStatus']
        ?? $row['service_status']
        ?? ''
    ))));
    if ($status === 'cancelled') {
        return false;
    }

    return ! (bool) ($row['latestCancelFinanciallySettled'] ?? false);
};
$paymentEligiblePersistedServiceLines = array_values(array_filter(
    $activePersistedServiceLines,
    $isPaymentEligibleServiceLine
));
$activeServicePaymentEligible = $isPaymentEligibleServiceLine($activeService);
$currentInvoiceAmountValue = (float) ($receivableTotals[$invoiceCurrency] ?? 0.0);
if ($currentInvoiceAmountValue <= 0.005) {
    $currentInvoiceAmountValue = array_reduce(
        $paymentEligiblePersistedServiceLines,
        static function (float $carry, array $row) use ($invoiceCurrency): float {
            if ((string) ($row['currency'] ?? 'PKR') !== $invoiceCurrency) {
                return $carry;
            }

            $savedFinalSale = (float) ($row['finalSalePrice'] ?? 0);
            if (abs($savedFinalSale) > 0.005) {
                return $carry + $savedFinalSale;
            }

            $rowCostCurrency = (string) ($row['costCurrency'] ?? $row['currency'] ?? $invoiceCurrency);
            $rowPricingExchangeRate = (float) ($row['pricingExchangeRate'] ?? 1);
            $rowConvertedPurchaseCost = round(
                (float) ($row['purchaseCost'] ?? 0)
                * (((string) ($row['currency'] ?? $invoiceCurrency) === $rowCostCurrency) ? 1 : max($rowPricingExchangeRate, 0)),
                2
            );
            $autoFinalSale = ((string) ($row['type'] ?? 'air ticket') === 'air ticket'
                ? $rowConvertedPurchaseCost
                : (float) ($row['salePrice'] ?? 0))
                + (float) ($row['serviceCharge'] ?? 0)
                + (float) ($row['vat'] ?? 0)
                - (float) ($row['discountAmount'] ?? 0);

            return $carry + $autoFinalSale;
        },
        0.0
    );
}
if ($currentInvoiceAmountValue <= 0.005 && $activeServicePaymentEligible && $invoiceCurrency === (string) ($activeService['currency'] ?? $invoiceCurrency)) {
    $currentInvoiceAmountValue = max(0, $activeServiceFinalSalePrice);
}
$currentOutstandingAmountValue = (float) ($outstandingTotals[$invoiceCurrency] ?? 0.0);
$currentReceivedPersistedAmount = (float) ($receivedTotals[$invoiceCurrency] ?? 0.0);
$canUseDraftInvoiceDueFallback = ! $hasCustomerFinance && $activeServicePaymentEligible;
$lockCurrentInvoiceBalance = $hasCustomerFinance && ! $activeServicePaymentEligible;
$activeServiceStatusKey = str_replace(' ', '_', mb_strtolower(trim((string) (
    $activeService['status']
    ?? $activeService['displayStatus']
    ?? $activeService['serviceStatus']
    ?? $activeService['service_status']
    ?? ''
))));
$activeServiceCancellationSettled = (bool) ($activeService['latestCancelFinanciallySettled'] ?? false);
$activeServiceIsCancelled = $activeServiceStatusKey === 'cancelled' || $activeServiceCancellationSettled;
$activeServiceCancellationNotice = '';
if ($activeServiceIsCancelled) {
    $activeServiceCancellationNotice = $activeServiceCancellationSettled
        ? 'This service is cancelled and settled. No new payment is due for this service.'
        : 'This service is cancelled. Use Edit Booking for settlement or refund follow-up.';
}
$effectiveCurrentInvoiceDueValue = $currentOutstandingAmountValue > 0.005
    ? $currentOutstandingAmountValue
    : (($currentInvoiceAmountValue > 0.005 && $canUseDraftInvoiceDueFallback) ? $currentInvoiceAmountValue : 0.0);
$currentInvoiceAmount = $invoiceCurrency . ' ' . number_format(round($currentInvoiceAmountValue, 0), 0);
$currentInvoiceBalance = $invoiceCurrency . ' ' . number_format(round($effectiveCurrentInvoiceDueValue, 0), 0);
$customerOpenBalanceTotals = is_array($customerPaymentFoundation['summary']['fullCustomerOutstanding'] ?? null)
    ? $customerPaymentFoundation['summary']['fullCustomerOutstanding']
    : [];
if ($effectiveCurrentInvoiceDueValue > 0.005) {
    $customerOpenBalanceTotals[$invoiceCurrency] = round(
        max((float) ($customerOpenBalanceTotals[$invoiceCurrency] ?? 0), $effectiveCurrentInvoiceDueValue),
        2
    );
}
$visibleCustomerOpenBalanceTotals = array_filter(
    $customerOpenBalanceTotals,
    static fn (float $amount): bool => abs($amount) > 0.005
);
$bookingReceivedTotals = [];
foreach (($customerPaymentFoundation['receipts'] ?? []) as $receiptRow) {
    $receiptStatusRaw = str_replace(' ', '_', mb_strtolower(trim((string) ($receiptRow['statusRaw'] ?? $receiptRow['status'] ?? ''))));
    if ($receiptStatusRaw === 'void') {
        continue;
    }

    $receiptCurrency = (string) ($receiptRow['currency'] ?? '');
    if ($receiptCurrency === '') {
        continue;
    }

    $bookingReceivedTotals[$receiptCurrency] = ($bookingReceivedTotals[$receiptCurrency] ?? 0.0)
        + (float) ($receiptRow['receivedAmount'] ?? 0);
}
$customerCreditTotals = is_array($customerPaymentFoundation['summary']['customerCredit'] ?? null)
    ? $customerPaymentFoundation['summary']['customerCredit']
    : [];
$customerCreditTotalsJson = json_encode(
    $customerCreditTotals,
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
) ?: '{}';
$sameCurrencyCustomerCreditAmount = (float) ($customerCreditTotals[$invoiceCurrency] ?? 0.0);
$sameCurrencyCustomerCreditDisplay = $invoiceCurrency . ' ' . number_format(round($sameCurrencyCustomerCreditAmount, 0), 0);
$sameCurrencyServiceCustomerRefundAmount = (float) ($activeService['customerRefundableCreditAmount'] ?? 0.0);
$sameCurrencyServiceCustomerRefundDisplay = $invoiceCurrency . ' ' . number_format(round($sameCurrencyServiceCustomerRefundAmount, 0), 0);
$sameCurrencySupplierRefundableAmount = (float) ($activeService['supplierRefundableCreditAmount'] ?? 0.0);
$sameCurrencySupplierRefundableDisplay = $invoiceCurrency . ' ' . number_format(round($sameCurrencySupplierRefundableAmount, 0), 0);
$settlementPaidHintAmount = (float) ($bookingReceivedTotals[$invoiceCurrency] ?? 0.0);
$settlementPaidHintDisplay = $invoiceCurrency . ' ' . number_format(round($settlementPaidHintAmount, 0), 0);
$paidOnCurrentInvoiceDisplay = $invoiceCurrency . ' ' . number_format(round($currentReceivedPersistedAmount, 0), 0);
$hasCurrentInvoiceAmount = $currentInvoiceAmountValue > 0.005 || $currentReceivedPersistedAmount > 0.005 || $effectiveCurrentInvoiceDueValue > 0.005;
$paymentCurrency = $invoiceCurrency;
$paymentTreasuryAccountsJson = json_encode(
    $customerPaymentFoundation['paymentTreasuryAccounts'] ?? [],
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
) ?: '[]';
$receiptDraftTreasuryAccountId = (int) ($receiptRecreateDraft['treasuryAccountId'] ?? 0);
$paymentBalanceAmount = $effectiveCurrentInvoiceDueValue;
$paymentBalance = $invoiceCurrency . ' ' . number_format(round($paymentBalanceAmount, 0), 0);
$currentInvoiceBalancePkrRate = is_numeric($customerPaymentFoundation['summary']['invoiceOutstandingPkrRate'] ?? null)
    ? (float) $customerPaymentFoundation['summary']['invoiceOutstandingPkrRate']
    : null;
$currentInvoiceBalancePkrEquivalentValue = is_numeric($customerPaymentFoundation['summary']['invoiceOutstandingPkrEquivalent'] ?? null)
    ? (float) $customerPaymentFoundation['summary']['invoiceOutstandingPkrEquivalent']
    : null;
$showCurrentInvoiceBalancePkrEquivalent = $invoiceCurrency !== 'PKR'
    && $currentInvoiceBalancePkrRate !== null
    && $currentInvoiceBalancePkrRate > 0;
$currentInvoiceBalancePkrEquivalent = $showCurrentInvoiceBalancePkrEquivalent && $currentInvoiceBalancePkrEquivalentValue !== null
    ? 'PKR ' . number_format(round($currentInvoiceBalancePkrEquivalentValue, 0), 0)
    : '-';
$amountReceivedNow = '0';
$receiptDraftMethod = (string) ($receiptRecreateDraft['paymentMethod'] ?? 'cash');
$receiptDraftDate = (string) ($receiptRecreateDraft['receiptDate'] ?? date('Y-m-d'));
$receiptDraftReferenceNumber = (string) ($receiptRecreateDraft['referenceNumber'] ?? '');
$receiptDraftBankCardDetail = (string) ($receiptRecreateDraft['bankCardDetail'] ?? '');
$receiptDraftRemarks = (string) ($receiptRecreateDraft['remarks'] ?? 'Payment received for current invoice');
if ($receiptRecreateDraft !== null) {
    $paymentCurrency = (string) ($receiptRecreateDraft['currency'] ?? $paymentCurrency);
    $amountReceivedNow = number_format(round((float) ($receiptRecreateDraft['receivedAmount'] ?? 0), 0), 0, '.', '');
}
$receiptRecreateClearUrl = url('/workspace?booking_id=' . (int) ($workspaceBooking['id'] ?? 0) . '#dock-panel-payments');
$supplierDraftName = (string) ($supplierPaymentRecreateDraft['supplier'] ?? '');
$supplierDraftDate = (string) ($supplierPaymentRecreateDraft['paymentDate'] ?? date('Y-m-d'));
$supplierDraftCurrency = (string) ($supplierPaymentRecreateDraft['currency'] ?? 'PKR');
$supplierDraftAmount = number_format(round((float) ($supplierPaymentRecreateDraft['paidAmount'] ?? 0), 0), 0, '.', '');
$supplierDraftMethod = (string) ($supplierPaymentRecreateDraft['paymentMethod'] ?? 'cash');
$supplierDraftReferenceNumber = (string) ($supplierPaymentRecreateDraft['referenceNumber'] ?? '');
$supplierDraftBankCardDetail = (string) ($supplierPaymentRecreateDraft['bankCardDetail'] ?? '');
$supplierDraftRemarks = (string) ($supplierPaymentRecreateDraft['remarks'] ?? '');
$supplierRecreateClearUrl = url('/workspace?booking_id=' . (int) ($workspaceBooking['id'] ?? 0) . '#dock-panel-suppliers');
$remindersReady = (int) ($workspaceBooking['id'] ?? 0) > 0;
$reminderEditResetUrl = url('/workspace?booking_id=' . (int) ($workspaceBooking['id'] ?? 0) . '#dock-panel-reminders');
$receivableAlertScopeLabels = [
    'customer' => 'This Passenger',
    'booking' => 'This Booking',
];
$receivableAlertSections = [
    'overdue' => 'Overdue',
    'dueToday' => 'Due Today',
    'pending' => 'Upcoming',
    'missingDueDate' => 'Missing Due Date',
];
$receivableAlertScopes = is_array($receivableAlerts['scopes'] ?? null) ? $receivableAlerts['scopes'] : [
    'customer' => [
        'overdue' => $receivableAlerts['overdue'] ?? [],
        'dueToday' => $receivableAlerts['dueToday'] ?? [],
        'pending' => $receivableAlerts['pending'] ?? [],
        'missingDueDate' => $receivableAlerts['missingDueDate'] ?? [],
        'counts' => $receivableAlerts['counts'] ?? [
            'overdue' => 0,
            'dueToday' => 0,
            'pending' => 0,
            'missingDueDate' => 0,
            'total' => 0,
        ],
    ],
    'booking' => [
        'overdue' => [],
        'dueToday' => [],
        'pending' => [],
        'missingDueDate' => [],
        'counts' => [
            'overdue' => 0,
            'dueToday' => 0,
            'pending' => 0,
            'missingDueDate' => 0,
            'total' => 0,
        ],
    ],
];
$requestedReceivableAlertScope = (string) ($receivableAlerts['defaultScope'] ?? 'customer');
$defaultReceivableAlertScope = in_array($requestedReceivableAlertScope, ['customer', 'booking'], true)
    ? $requestedReceivableAlertScope
    : 'customer';
$receivableAlertCountsByScope = [];
$receivableAlertRowsByScope = [];
foreach ($receivableAlertScopes as $scopeKey => $scopeAlerts) {
    $receivableAlertCountsByScope[$scopeKey] = is_array($scopeAlerts['counts'] ?? null) ? $scopeAlerts['counts'] : [
        'overdue' => 0,
        'dueToday' => 0,
        'pending' => 0,
        'missingDueDate' => 0,
        'total' => 0,
    ];
    $receivableAlertRowsByScope[$scopeKey] = [];
    foreach ($receivableAlertSections as $alertKey => $alertLabel) {
        foreach (($scopeAlerts[$alertKey] ?? []) as $alertRow) {
            $receivableAlertRowsByScope[$scopeKey][] = [
            'category' => $alertLabel,
            'bookingReference' => (string) ($alertRow['bookingReference'] ?? ''),
            'customerName' => (string) ($alertRow['customerName'] ?? ''),
            'branchName' => trim((string) (($alertRow['branchName'] ?? '') . (! empty($alertRow['branchCity']) ? ', ' . $alertRow['branchCity'] : ''))),
            'currency' => (string) ($alertRow['currency'] ?? 'PKR'),
            'outstandingAmount' => (float) ($alertRow['outstandingAmount'] ?? 0),
            'dueDate' => (string) ($alertRow['dueDate'] ?? ''),
            'daysLabel' => (string) ($alertRow['daysLabel'] ?? ''),
            'serviceSummary' => (string) ($alertRow['serviceSummary'] ?? ''),
            'openUrl' => url('/workspace?booking_id=' . (int) ($alertRow['bookingId'] ?? 0)),
            'isCurrentBooking' => ! empty($alertRow['isCurrentBooking']),
            ];
        }
    }
}
$receivableAlertRows = $receivableAlertRowsByScope[$defaultReceivableAlertScope] ?? [];
$activeReceivableAlertCounts = $receivableAlertCountsByScope[$defaultReceivableAlertScope] ?? [
    'overdue' => 0,
    'dueToday' => 0,
    'pending' => 0,
    'missingDueDate' => 0,
    'total' => 0,
];
$serviceTypeOptions = is_array($serviceTypeOptions ?? null) ? $serviceTypeOptions : [];
$paymentMethodOptions = is_array($paymentMethodOptions ?? null) ? $paymentMethodOptions : [];
if ($serviceTypeOptions === []) {
    $serviceTypeOptions = [
        'air ticket' => 'Air Ticket',
        'visa' => 'Visa',
        'umrah' => 'Umrah',
        'tourism' => 'Tourism',
        'hotel' => 'Hotel',
        'transport' => 'Transport',
        'other' => 'Other Package',
    ];
}
if ($paymentMethodOptions === []) {
    $paymentMethodOptions = [
        'cash' => 'Cash',
        'bank_transfer' => 'Bank Transfer',
        'debit_card' => 'Debit Card',
        'credit_card' => 'Credit Card',
    ];
}
if (! array_key_exists((string) ($activeService['type'] ?? ''), $serviceTypeOptions) && (string) ($activeService['type'] ?? '') !== '') {
    $serviceTypeOptions[(string) $activeService['type']] = ucwords((string) $activeService['type']);
}
foreach ([$receiptDraftMethod, $supplierDraftMethod] as $knownPaymentMethod) {
    if ($knownPaymentMethod !== '' && ! array_key_exists($knownPaymentMethod, $paymentMethodOptions)) {
        $paymentMethodOptions[$knownPaymentMethod] = ucwords(str_replace('_', ' ', $knownPaymentMethod));
    }
}
$refundPaymentMethodOptions = array_intersect_key(
    $paymentMethodOptions,
    array_flip(['cash', 'bank_transfer', 'debit_card', 'credit_card'])
);
if ($refundPaymentMethodOptions === []) {
    $refundPaymentMethodOptions = [
        'cash' => 'Cash',
        'bank_transfer' => 'Bank Transfer',
        'debit_card' => 'Debit Card',
        'credit_card' => 'Credit Card',
    ];
}
$customerOpenReceivablesJson = json_encode(
    $customerPaymentFoundation['customerOpenReceivables'] ?? [],
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
) ?: '[]';
$dailySettlementRatesJson = json_encode(
    $customerPaymentFoundation['dailySettlementRates'] ?? [],
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
) ?: '{}';
$paymentReceiptsJson = json_encode(
    $customerPaymentFoundation['receipts'] ?? [],
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
) ?: '[]';
$paymentHistoryAllocations = is_array($customerPaymentFoundation['invoicePaymentHistory'] ?? null)
    ? $customerPaymentFoundation['invoicePaymentHistory']
    : ($customerPaymentFoundation['allocations'] ?? []);
$paymentAllocationsJson = json_encode(
    $paymentHistoryAllocations,
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
) ?: '[]';
$serviceSupplierOptionsJson = json_encode(
    $serviceSupplierOptions ?? [],
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
) ?: '[]';
$businessSourceOptionsJson = json_encode(
    $businessSourceOptions ?? [],
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
) ?: '[]';
$serviceTaxTotal = static function (array $row): float {
    return (float) ($row['spyiAmount'] ?? 0)
        + (float) ($row['aqYrPkAmount'] ?? 0)
        + (float) ($row['yqAmount'] ?? 0)
        + (float) ($row['othAmount'] ?? 0)
        + (float) ($row['vatInput'] ?? 0)
        + (float) ($row['taxes'] ?? 0);
};
$activeServiceFrTxRawValue = ((string) ($activeService['type'] ?? 'air ticket') === 'air ticket')
    ? ((float) ($activeService['salePrice'] ?? 0) + $serviceTaxTotal($activeService))
    : (float) ($activeService['purchaseCost'] ?? 0);

$activeServiceFrTxValue = $formatMoney($activeServiceFrTxRawValue);
$basicFareTotal = $formatCurrencyTotals($sumByCurrency($activePersistedServiceLines, 'currency', static fn (array $row): float => (float) ($row['fare'] ?? 0)));
$taxTotal = $formatCurrencyTotals($sumByCurrency($activePersistedServiceLines, 'currency', static fn (array $row): float => (float) ($row['spyiAmount'] ?? 0) + (float) ($row['aqYrPkAmount'] ?? 0) + (float) ($row['yqAmount'] ?? 0) + (float) ($row['othAmount'] ?? 0) + (float) ($row['vatInput'] ?? 0) + (float) ($row['taxes'] ?? 0)));
$otherTotal = $formatCurrencyTotals($sumByCurrency($activePersistedServiceLines, 'currency', static fn (array $row): float => (float) ($row['serviceCharge'] ?? 0)));
$serviceSaleTotal = $workspaceBooking['totalSale'];
$airlinePayable = $workspaceBooking['totalPayable'];
$clientReceivable = $workspaceBooking['totalReceivable'];
$otherPayable = 'PKR 0';
$profitLoss = $workspaceBooking['profitLoss'];
$activeServiceFrTxRawValue = ((string) ($activeService['type'] ?? 'air ticket') === 'air ticket')
    ? ((float) ($activeService['salePrice'] ?? 0) + $serviceTaxTotal($activeService))
    : (float) ($activeService['purchaseCost'] ?? 0);

$activeServiceLossDelta = max(0, round($activeServiceConvertedPurchaseCost - $activeServiceFinalSalePrice, 2));
$activeServiceHasLoss = $activeServiceLossDelta > 0.005;
$passengerName = (string) (($activeService['passengerName'] ?? '') !== '' ? $activeService['passengerName'] : ($leadTraveler['fullName'] !== '' ? $leadTraveler['fullName'] : $workspaceBooking['lead']));
$sectorDescription = trim((string) (($activeService['sectorFrom'] ?? '') . (($activeService['sectorTo'] ?? '') !== '' ? '-' . $activeService['sectorTo'] : '')));
$sectorDescription = $sectorDescription !== '' ? $sectorDescription : (string) ($activeService['remarks'] ?? '');
$routeRawValue = strtoupper((string) preg_replace(
    '/[^A-Za-z0-9]/',
    '',
    (string) (($activeService['sectorFrom'] ?? '') . ($activeService['sectorTo'] ?? ''))
));
$routeRawValue = substr($routeRawValue, 0, 9);
$routeLabel = '---/---/---';
for ($routeIndex = 0, $routeLength = strlen($routeRawValue), $routeOffset = 0; $routeIndex < $routeLength; $routeIndex++) {
    if ($routeIndex === 3 || $routeIndex === 6) {
        $routeOffset++;
    }
    $routeLabel[$routeIndex + $routeOffset] = $routeRawValue[$routeIndex];
}
$serviceReference = (string) ($activeService['ticketNumber'] ?? '');
$serviceReference = $serviceReference !== '' ? $serviceReference : (string) ($activeService['lineNumber'] ?? 'SV-DRAFT');
$ledgerUrl = $workspaceBooking['id'] > 0
    ? url('/workspace/output?booking_id=' . (int) $workspaceBooking['id'] . '&doc=account_statement')
    : '#';
$branchLabelForWhatsapp = '';
foreach ($branchOptions as $branchOption) {
    if ((int) ($branchOption['id'] ?? 0) === (int) ($workspaceBooking['branchId'] ?? 0)) {
        $branchLabelForWhatsapp = (string) ($branchOption['name'] ?? '');
        break;
    }
}
$duesFinderUrl = url('/workspace/customers/dues-finder');
$supplierHistoryFinderUrl = url('/workspace/suppliers/history-finder');
$invoiceNoLabel = $workspaceBooking['id'] > 0 ? $workspaceBooking['number'] : 'Draft';
$showWorkspaceDebug = app_debug_tools_enabled() && (string) ($_GET['debug_ui'] ?? '') === '1';
$canPostServiceEvents = in_array((string) ($user['roleCode'] ?? $user['role_code'] ?? ''), ['super_admin', 'branch_admin'], true);
$invoiceOutstandingAmount = $effectiveCurrentInvoiceDueValue;
$invoiceReceivedAmount = $currentReceivedPersistedAmount;
$hasRealInvoiceState = $currentInvoiceAmountValue > 0.005 || $effectiveCurrentInvoiceDueValue > 0.005 || $hasActiveServices;
$documentsReady = (int) ($workspaceBooking['id'] ?? 0) > 0;
$paymentStatus = 'Draft';
$paymentStatusClass = 'draft';
if ($workspaceBooking['id'] > 0 || $hasRealInvoiceState) {
    if ($invoiceOutstandingAmount <= 0.005 && $hasRealInvoiceState) {
        $paymentStatus = 'Paid';
        $paymentStatusClass = 'paid';
    } elseif ($invoiceOutstandingAmount > 0.005 && $invoiceDueDate !== '' && strtotime($invoiceDueDate) !== false && strtotime($invoiceDueDate) < strtotime(date('Y-m-d'))) {
        $paymentStatus = 'Overdue';
        $paymentStatusClass = 'overdue';
    } elseif ($invoiceOutstandingAmount > 0.005 && $invoiceReceivedAmount > 0.005) {
        $paymentStatus = 'Partially Paid';
        $paymentStatusClass = 'partial';
    } else {
        $paymentStatus = 'Unpaid';
        $paymentStatusClass = 'unpaid';
    }
}

$dueHelper = '';
if ($workspaceBooking['id'] <= 0) {
    $dueHelper = '';
} elseif ($invoiceDueDate !== '' && strtotime($invoiceDueDate) !== false) {
    $today = new DateTimeImmutable(date('Y-m-d'));
    $dueDate = new DateTimeImmutable($invoiceDueDate);
    $days = (int) $today->diff($dueDate)->format('%r%a');
    if ($days === 0) {
        $dueHelper = 'Due Today';
    } elseif ($days > 0) {
        $dueHelper = 'Due in ' . $days . ' day' . ($days === 1 ? '' : 's');
    } else {
        $dueHelper = 'Overdue by ' . abs($days) . ' day' . ($days === -1 ? '' : 's');
    }
}

$supplierGrossTotals = $sumByCurrency($supplierFoundation['obligations'] ?? [], 'currency', static fn (array $row): float => (float) ($row['grossAmount'] ?? 0));
$supplierAdvanceAppliedTotals = $sumByCurrency($supplierFoundation['obligations'] ?? [], 'currency', static fn (array $row): float => (float) ($row['advanceAppliedAmount'] ?? 0));
$supplierPaidTotals = $sumByCurrency($supplierFoundation['payments'] ?? [], 'currency', static fn (array $row): float => (float) ($row['paidAmount'] ?? 0));
$supplierOutstandingTotals = $sumByCurrency($supplierFoundation['obligations'] ?? [], 'currency', static fn (array $row): float => (float) ($row['balanceDueAmount'] ?? $row['netPayableAmount'] ?? 0));
$supplierAdvanceBalanceTotals = $sumByCurrency($supplierFoundation['advances'] ?? [], 'currency', static fn (array $row): float => (float) ($row['availableAmount'] ?? 0));
$latestRefundPrintUrl = (int) ($workspaceBooking['id'] ?? 0) > 0 && (int) ($activeService['latestRefundEventId'] ?? 0) > 0
    ? url('/workspace/output?booking_id=' . (int) $workspaceBooking['id'] . '&doc=service_refund_receipt&refund_event_id=' . (int) ($activeService['latestRefundEventId'] ?? 0))
    : '';
?>

<section class="legacy-workspace" data-workspace-station data-service-engine data-has-services="<?= $hasActiveServices ? '1' : '0' ?>" data-has-selected-customer="<?= ((int) ($selectedTravelerProfile['id'] ?? 0) > 0 || trim((string) ($workspaceBooking['lead'] ?? '')) !== '') ? '1' : '0' ?>" data-workspace-opened-existing-booking="<?= !empty($workspaceIsNew) ? '0' : ((int) ($workspaceBooking['id'] ?? 0) > 0 ? '1' : '0') ?>" data-new-booking-url="<?= e(url('/workspace?new=1&focus=customer')) ?>" data-autosave-invoice-url="<?= e(url('/workspace/autosave/invoice')) ?>" data-autosave-service-url="<?= e(url('/workspace/autosave/service')) ?>" data-supplier-register-url="<?= e(url('/workspace/suppliers/register')) ?>" data-business-source-register-url="<?= e(url('/workspace/business-sources/register')) ?>" data-supplier-advance-lookup-url="<?= e(url('/suppliers/advances/available')) ?>" data-treasury-accounts-url="<?= e(url('/treasury/accounts')) ?>" data-payment-treasury-accounts-url="<?= e((string) ($paymentTreasuryAccountsUrl ?? '')) ?>" data-client-error-log-url="<?= e(url('/workspace/client-log')) ?>" data-offline-ping-url="<?= e(url('/offline/ping')) ?>" data-offline-snapshot-url="<?= e(url('/offline/snapshot')) ?>" data-offline-sync-url="<?= e(url('/offline/drafts/sync')) ?>" data-csrf-token="<?= e(\App\Helpers\Csrf::token()) ?>" data-can-void-financials="<?= $canPostServiceEvents ? '1' : '0' ?>" data-debug-tools-enabled="<?= $showWorkspaceDebug ? '1' : '0' ?>">
    <div class="workspace-feedback" data-workspace-feedback aria-live="polite"></div>
    <?php if ($showWorkspaceDebug && $serviceSaveDebugJson !== null): ?>
        <pre class="commercial-debug-block" style="margin:8px 0 12px; white-space:pre-wrap;">Service save debug
<?= e($serviceSaveDebugJson) ?></pre>
    <?php endif; ?>

    <div class="legacy-command-bar">
        <div class="legacy-command-row legacy-command-row--primary">
            <div class="legacy-command-group">
                <button class="btn btn-primary btn-sm" type="button" accesskey="n" data-workspace-action="new-booking">New Invoice</button>
                <button class="btn btn-sm" type="button" data-workspace-action="add-traveler" onclick="return window.workspaceOpenStandaloneCustomerModal && window.workspaceOpenStandaloneCustomerModal(event)">Find Customer</button>
                <button class="btn btn-sm" type="button" data-workspace-action="new-customer" onclick="return window.workspaceOpenStandaloneNewCustomerModal && window.workspaceOpenStandaloneNewCustomerModal(event)">New Customer</button>
                <button class="btn btn-sm" type="button" data-workspace-action="customer-dues-finder">Receive Customer Payment</button>
                <button class="btn btn-sm" type="button" data-workspace-action="supplier-history-finder">Find Supplier Payment</button>
                <button class="btn btn-sm" type="button" data-global-prepaid-supplier-open>Prepaid Supplier Payment</button>
                <button class="btn btn-sm" type="button" data-workspace-action="add-service" data-workflow-control="add-service" <?= $hasActiveServices ? '' : 'disabled' ?>>Add Service</button>
                <button class="btn btn-sm" type="button" data-workspace-action="add-reminder">Reminders</button>
                <button class="btn btn-sm" type="button" data-workspace-action="recent-bookings">Recent Invoices</button>
            </div>
        </div>
        <div class="legacy-command-row legacy-command-row--secondary">
            <div class="legacy-command-group legacy-command-group--secondary">
                <button class="btn btn-sm" type="button" data-workspace-action="service-edit-booking" disabled>Edit Booking</button>
                <button class="btn btn-sm" type="button" data-workspace-action="service-penalty-refund" disabled>Edit Penalty / Refund</button>
                <button class="btn btn-sm" type="button" data-offline-action="snapshot">Offline Snapshot</button>
                <button class="btn btn-sm" type="button" data-offline-action="sync">Sync Offline</button>
            </div>
            <form id="workspace-search-form" class="legacy-quick-search" method="get" action="<?= e(url('/workspace')) ?>">
                <label for="workspace-search">Quick Search</label>
                <input id="workspace-search" type="text" name="q" value="<?= e($currentSearchTerm) ?>" placeholder="Invoice / receipt / customer / mobile / passport / PNR / supplier">
                <button class="btn btn-primary btn-sm" type="submit" accesskey="s" data-workspace-search-submit>Search</button>
            </form>
        </div>
    </div>
    <section id="workspace-recent-bookings" class="legacy-band legacy-search-results" hidden>
        <div class="legacy-service-context legacy-search-results__header">
            <strong>Recent Invoices</strong>
            <div class="legacy-search-results__header-actions">
                <span><?= e(count($recentBookings)) ?> item<?= count($recentBookings) === 1 ? '' : 's' ?></span>
                <button
                    class="btn btn-sm legacy-search-results__close"
                    type="button"
                    data-workspace-close-section="workspace-recent-bookings"
                >Close</button>
            </div>
        </div>
        <?php if ($recentBookings === []): ?>
            <div class="workspace-feedback workspace-feedback--inline legacy-search-results__empty" style="display:block;">No recent invoices found.</div>
        <?php else: ?>
            <div class="legacy-search-results__table-wrap">
                <table class="legacy-search-results__table">
                    <thead>
                    <tr>
                        <th>Booking ID</th>
                        <th>Customer</th>
                        <th>Passenger</th>
                        <th>PNR</th>
                        <th>Route</th>
                        <th>Invoice Amount</th>
                        <th>Paid</th>
                        <th>Balance</th>
                        <th>Open</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($recentBookings as $recentBooking): ?>
                        <?php
                        $recentCurrency = trim((string) ($recentBooking['invoice_currency'] ?? $recentBooking['base_currency'] ?? 'PKR'));
                        if ($recentCurrency === '') {
                            $recentCurrency = 'PKR';
                        }
                        ?>
                        <tr>
                            <td><?= e((string) ($recentBooking['booking_reference'] ?? '')) ?></td>
                            <td><?= e((string) ($recentBooking['customer_name'] ?? '')) ?></td>
                            <td><?= e((string) ($recentBooking['passenger_names'] ?? 'N/A')) ?></td>
                            <td><?= e((string) ($recentBooking['pnrs'] ?? 'N/A')) ?></td>
                            <td><?= e((string) ($recentBooking['routes'] ?? 'N/A')) ?></td>
                            <td><?= e($recentCurrency . ' ' . number_format((float) ($recentBooking['invoice_amount'] ?? 0), 0)) ?></td>
                            <td><?= e($recentCurrency . ' ' . number_format((float) ($recentBooking['paid_amount'] ?? 0), 0)) ?></td>
                            <td><?= e($recentCurrency . ' ' . number_format((float) ($recentBooking['outstanding_amount'] ?? 0), 0)) ?></td>
                            <td><a class="btn btn-sm legacy-search-results__open" href="<?= e(url('/workspace?booking_id=' . (int) ($recentBooking['id'] ?? 0))) ?>">Open</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
    <div class="workspace-feedback workspace-feedback--inline legacy-offline-edit-lock" data-offline-edit-lock hidden>
        Offline mode: customer search and new customer capture are available. Booking, service, and payment changes are locked until connection returns.
    </div>

    <section id="offline-queue-preview" class="legacy-band legacy-offline-queue-preview" data-offline-queue-preview hidden>
        <div class="legacy-service-context legacy-offline-queue-preview__header">
            <strong>Offline Queue</strong>
            <span data-offline-queue-count>0 draft(s)</span>
        </div>
        <div class="legacy-offline-queue-preview__list" data-offline-queue-list></div>
    </section>

    <section class="legacy-band legacy-search-results" data-offline-search-results hidden>
        <div class="legacy-service-context legacy-search-results__header">
            <strong data-offline-search-title>Offline Snapshot Results</strong>
            <span data-offline-search-count>0 matches</span>
        </div>
        <div class="workspace-feedback workspace-feedback--inline legacy-search-results__empty" data-offline-search-empty hidden style="display:block;">No cached offline result matched this search.</div>
        <div class="legacy-search-results__table-wrap" data-offline-search-table-wrap hidden>
            <table class="legacy-search-results__table">
                <thead>
                <tr>
                    <th>Invoice No.</th>
                    <th>Customer</th>
                    <th>Branch</th>
                    <th>Date</th>
                    <th>Currency</th>
                    <th>Outstanding</th>
                    <th>Service Ref.</th>
                    <th>Ticket / PNR</th>
                    <th>Open</th>
                </tr>
                </thead>
                <tbody data-offline-search-results-body></tbody>
            </table>
        </div>
    </section>

    <?php if ($currentSearchTerm !== '' && count($bookingSearchResults) !== 1): ?>
        <section class="legacy-band legacy-search-results">
            <div class="legacy-service-context legacy-search-results__header">
                <strong>Search Results for “<?= e($currentSearchTerm) ?>”</strong>
                <span><?= e(count($bookingSearchResults)) ?> match<?= count($bookingSearchResults) === 1 ? '' : 'es' ?></span>
            </div>
            <?php if ($bookingSearchResults === []): ?>
                <div class="workspace-feedback workspace-feedback--inline legacy-search-results__empty" style="display:block;">No accessible booking found.</div>
            <?php else: ?>
                <div class="legacy-search-results__table-wrap">
                    <table class="legacy-search-results__table">
                        <thead>
                        <tr>
                            <th>Invoice No.</th>
                            <th>Customer</th>
                            <th>Branch</th>
                            <th>Date</th>
                            <th>Currency</th>
                            <th>Outstanding</th>
                            <th>Service Ref.</th>
                            <th>Ticket / PNR</th>
                            <th>Open</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($bookingSearchResults as $searchRow): ?>
                            <?php
                            $searchCurrency = trim((string) ($searchRow['booking_currency'] ?? $searchRow['base_currency'] ?? 'PKR'));
                            if ($searchCurrency === '') {
                                $searchCurrency = 'PKR';
                            }
                            $ticketOrPnr = trim((string) ($searchRow['ticket_number'] ?? ''));
                            if ($ticketOrPnr === '') {
                                $ticketOrPnr = trim((string) ($searchRow['pnr'] ?? ''));
                            } elseif (trim((string) ($searchRow['pnr'] ?? '')) !== '') {
                                $ticketOrPnr .= ' / ' . trim((string) $searchRow['pnr']);
                            }
                            ?>
                            <tr>
                                <td><?= e((string) ($searchRow['booking_reference'] ?? '')) ?></td>
                                <td><?= e((string) ($searchRow['lead_traveler_name'] ?? '')) ?></td>
                                <td><?= e((string) ($searchRow['branch_name'] ?? '')) ?></td>
                                <td><?= e((string) ($searchRow['booking_date'] ?? '')) ?></td>
                                <td><?= e($searchCurrency) ?></td>
                                <td><?= e($searchCurrency . ' ' . number_format((float) ($searchRow['total_outstanding'] ?? 0), 2)) ?></td>
                                <td><?= e((string) (($searchRow['service_line_reference'] ?? '') !== '' ? $searchRow['service_line_reference'] : '-')) ?></td>
                                <td><?= e($ticketOrPnr !== '' ? $ticketOrPnr : '-') ?></td>
                                <td><a class="btn btn-sm legacy-search-results__open" href="<?= e(url('/workspace?booking_id=' . (int) ($searchRow['id'] ?? 0))) ?>">Open</a></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <form class="legacy-band legacy-invoice-header legacy-invoice-header--repaired" method="post" action="<?= e(url('/workspace/save')) ?>">
        <?= \App\Helpers\Csrf::input() ?>
        <?php
        $selectedBusinessSourceId = (int) ($workspaceBooking['businessSourceId'] ?? 0);
        if ($selectedBusinessSourceId <= 0 && $businessSourceOptions !== []) {
            $selectedBusinessSourceId = (int) ($businessSourceOptions[0]['id'] ?? 0);
        }
        $businessSourceOptionIds = array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $businessSourceOptions);
        ?>
        <input type="hidden" name="booking_id" value="<?= e((string) $workspaceBooking['id']) ?>">
        <input type="hidden" name="selected_customer_id" value="<?= e((string) ($selectedTravelerProfile['id'] ?? 0)) ?>" data-booking-selected-customer-id>
        <div class="legacy-invoice-header__row legacy-invoice-header__row--scope">
            <label class="legacy-field legacy-invoice-header__account"><span>Account</span><select name="business_source_id" data-business-source-input>
                <?php foreach ($businessSourceOptions as $businessSourceRow): ?>
                    <?php $businessSourceRowId = (int) ($businessSourceRow['id'] ?? 0); ?>
                    <option value="<?= e((string) $businessSourceRowId) ?>" <?= $businessSourceRowId === $selectedBusinessSourceId ? 'selected' : '' ?>><?= e((string) ($businessSourceRow['name'] ?? '')) ?></option>
                <?php endforeach; ?>
                <?php if ($selectedBusinessSourceId > 0 && ! in_array($selectedBusinessSourceId, $businessSourceOptionIds, true) && trim((string) ($workspaceBooking['businessSourceName'] ?? '')) !== ''): ?>
                    <option value="<?= e((string) $selectedBusinessSourceId) ?>" selected><?= e((string) ($workspaceBooking['businessSourceName'] ?? '')) ?></option>
                <?php endif; ?>
                <option value="__add_business_source__">+ Add new account...</option>
            </select></label>
            <label class="legacy-field legacy-invoice-header__branch"><span>Branch</span><select name="branch_id"><?php foreach ($branchOptions as $branchRow): ?><option value="<?= e((string) $branchRow['id']) ?>" <?= (int) $branchRow['id'] === (int) $workspaceBooking['branchId'] ? 'selected' : '' ?>><?= e((string) $branchRow['name']) ?></option><?php endforeach; ?></select></label>
        </div>
        <input type="hidden" name="due_date" value="<?= e($invoiceDueDate) ?>" data-booking-due-date-field>
        <input type="hidden" name="contact_mobile" value="<?= e($workspaceBooking['mobile']) ?>" data-booking-mobile-field>
        <input type="hidden" name="passport_number" value="<?= e($workspaceBooking['passport']) ?>" data-booking-passport-field>
        <input type="hidden" name="departure_date" value="<?= e($workspaceBooking['departureDate']) ?>">
        <input type="hidden" name="return_date" value="<?= e($workspaceBooking['returnDate']) ?>">
        <input type="hidden" value="<?= e($branchLabelForWhatsapp) ?>" data-booking-branch-label>
        <input type="hidden" name="party_notes" value="<?= e($workspaceBooking['partyNotes']) ?>">

        <div class="legacy-invoice-header__row legacy-invoice-header__row--primary">
            <label class="legacy-field legacy-field--customer station-field--autocomplete legacy-invoice-header__customer" data-customer-inline-search>
                <span>Customer Name</span>
                <input id="lead-traveler-focus" type="text" name="lead_traveler_name" value="<?= e($workspaceBooking['lead']) ?>" autocomplete="off" placeholder="Type customer name / family ID / passport / mobile" data-customer-autocomplete-input onfocus="window.workspaceOpenInlineCustomerLookup && window.workspaceOpenInlineCustomerLookup(this)" onclick="window.workspaceOpenInlineCustomerLookup && window.workspaceOpenInlineCustomerLookup(this)" oninput="window.workspaceHandleInlineCustomerLookupInput && window.workspaceHandleInlineCustomerLookupInput(this)" onkeydown="window.workspaceHandleInlineCustomerLookupKeydown && window.workspaceHandleInlineCustomerLookupKeydown(event, this)">
                <div class="customer-inline-picker" data-customer-autocomplete-panel hidden>
                    <div class="customer-inline-picker__results" data-customer-autocomplete-results></div>
                </div>
            </label>
<label class="legacy-field legacy-field--red legacy-invoice-header__invoice-no"><span>Invoice No.</span><input type="text" value="<?= e($invoiceNoLabel) ?>" readonly data-invoice-number-display><small data-autosave-status hidden><?= $workspaceBooking['id'] > 0 ? 'Saved' : 'Draft' ?></small></label>
            <label class="legacy-field legacy-invoice-header__receipt-no"><span>Receipt No.</span><input type="text" value="<?= e($receiptNo) ?>" readonly></label>
            <label class="legacy-field legacy-invoice-header__date"><span>Invoice Date</span><input type="date" name="booking_date" value="<?= e($workspaceBooking['bookingDate']) ?>"></label>
            <label class="legacy-field legacy-invoice-header__client-code"><span>Client Code</span><input type="text" value="<?= e($clientCode) ?>" data-customer-summary-client readonly></label>
            <label class="legacy-field legacy-invoice-header__type"><span>Invoice Status</span><select name="booking_status"><?php foreach ($bookingStatusOptions as $statusKey => $statusLabel): ?><option value="<?= e($statusKey) ?>" <?= $statusKey === $workspaceBooking['status'] ? 'selected' : '' ?>><?= e(strtoupper($statusLabel)) ?></option><?php endforeach; ?></select></label>
            <label class="legacy-field legacy-invoice-header__mobile"><span>Mobile</span><input type="text" value="<?= e($workspaceBooking['mobile']) ?>" data-customer-summary-mobile readonly></label>
            <label class="legacy-field legacy-invoice-header__due"><span>Due Date</span><input type="date" value="<?= e($invoiceDueDate) ?>" readonly data-booking-due-date-display></label>
            <label class="legacy-field legacy-invoice-header__family"><span>Family ID</span><input type="text" value="<?= e($familyId) ?>" data-customer-summary-family readonly></label>
            <label class="legacy-field legacy-invoice-header__color"><span>Color Tag</span><input type="text" value="<?= e($customerColorTag) ?>" data-customer-summary-color readonly></label>
        </div>

        <div class="legacy-invoice-header__row legacy-invoice-header__row--support">
            <label class="legacy-field legacy-invoice-header__title"><span>Invoice Title - C/o</span><input type="text" name="party_label" value="<?= e($workspaceBooking['partyLabel']) ?>"></label>
            <label class="legacy-field legacy-field--remarks legacy-invoice-header__remarks"><span>Invoice Remarks</span><input type="text" name="remarks" value="<?= e($workspaceBooking['remarks']) ?>"></label>
        </div>
        <button class="legacy-invoice-header__submit-helper" type="submit" tabindex="-1" aria-hidden="true" hidden><?= $workspaceBooking['id'] > 0 ? 'Update' : 'Save' ?></button>
    </form>

    <form id="legacy-service-form" class="legacy-band legacy-service-band" method="post" action="<?= e(url('/workspace/services/save')) ?>" data-workflow-gate="service-entry">
        <?= \App\Helpers\Csrf::input() ?>
        <input type="hidden" name="booking_id" value="<?= e((string) $workspaceBooking['id']) ?>">
        <input type="hidden" name="auto_branch_id" value="<?= e((string) $workspaceBooking['branchId']) ?>" data-auto-booking-field="branch_id">
        <input type="hidden" name="auto_business_source_id" value="<?= e((string) $selectedBusinessSourceId) ?>" data-auto-booking-field="business_source_id">
        <input type="hidden" name="auto_selected_customer_id" value="<?= e((string) ($selectedTravelerProfile['id'] ?? 0)) ?>" data-auto-booking-field="selected_customer_id">
        <input type="hidden" name="auto_booking_date" value="<?= e($workspaceBooking['bookingDate']) ?>" data-auto-booking-field="booking_date">
        <input type="hidden" name="auto_due_date" value="<?= e($invoiceDueDate) ?>" data-auto-booking-field="due_date">
        <input type="hidden" name="auto_booking_status" value="<?= e($workspaceBooking['status']) ?>" data-auto-booking-field="booking_status">
        <input type="hidden" name="auto_party_label" value="<?= e($workspaceBooking['partyLabel']) ?>" data-auto-booking-field="party_label">
        <input type="hidden" name="auto_lead_traveler_name" value="<?= e($workspaceBooking['lead']) ?>" data-auto-booking-field="lead_traveler_name">
        <input type="hidden" name="auto_contact_mobile" value="<?= e($workspaceBooking['mobile']) ?>" data-auto-booking-field="contact_mobile">
        <input type="hidden" name="auto_passport_number" value="<?= e($workspaceBooking['passport']) ?>" data-auto-booking-field="passport_number">
        <input type="hidden" name="auto_departure_date" value="<?= e($workspaceBooking['departureDate']) ?>" data-auto-booking-field="departure_date">
        <input type="hidden" name="auto_return_date" value="<?= e($workspaceBooking['returnDate']) ?>" data-auto-booking-field="return_date">
        <input type="hidden" name="auto_party_notes" value="<?= e($workspaceBooking['partyNotes']) ?>" data-auto-booking-field="party_notes">
        <input type="hidden" name="auto_booking_remarks" value="<?= e($workspaceBooking['remarks']) ?>" data-auto-booking-field="remarks">
        <input type="hidden" name="service_id" value="<?= e((string) ($activeService['serviceId'] ?? 0)) ?>" data-service-field="serviceId">
        <div class="legacy-service-context">
            <span data-active-service-type><?= e(ucwords((string) ($activeService['type'] ?? 'Air Ticket'))) ?></span>
        </div>

        <label class="legacy-field legacy-field--passenger"><span>Passenger Name</span><input id="active-service-passenger-name" type="text" name="service_passenger_name" list="service-passenger-options" value="<?= e($passengerName) ?>" data-service-passenger-name autocomplete="off"><input type="hidden" name="service_traveler_id" value="<?= e((string) ($activeService['travelerId'] ?? 0)) ?>" data-service-field="travelerId"></label>
        <label class="legacy-field legacy-field--xs"><span>Mode</span><input type="text" value="P" readonly></label>
        <label class="legacy-field legacy-field--service-type"><span>Service Type</span><select name="service_type" data-service-field="type"><?php foreach ($serviceTypeOptions as $serviceTypeValue => $serviceTypeLabel): ?><option value="<?= e((string) $serviceTypeValue) ?>" <?= (string) $serviceTypeValue === (string) ($activeService['type'] ?? 'air ticket') ? 'selected' : '' ?>><?= e((string) $serviceTypeLabel) ?></option><?php endforeach; ?></select></label>
        <label class="legacy-field legacy-field--ticket-ref"><span data-service-ref-label>Ticket No. / Ref No.</span><input type="text" name="ticket_number" data-ticket-field="ticket_number" value="<?= e($serviceReference) ?>"></label>
        <label class="legacy-field legacy-field--pnr"><span data-service-second-ref-label>PNR.#</span><input type="text" name="ticket_pnr" data-ticket-field="pnr" value="<?= e((string) ($activeService['pnr'] ?? '')) ?>"></label>
        <label class="legacy-field legacy-field--supplier"><span data-service-supplier-label>Tkt.Purchase From</span><select name="supplier_name" data-service-field="supplier" data-service-supplier-input>
            <?php $activeSupplierName = trim((string) ($activeService['supplier'] ?? '')); ?>
            <?php $supplierOptionNames = array_map(static fn (array $row): string => trim((string) ($row['name'] ?? '')), $serviceSupplierOptions); ?>
            <option value="" <?= $activeSupplierName === '' ? 'selected' : '' ?>>Select supplier</option>
            <?php foreach ($serviceSupplierOptions as $supplierOption): ?>
                <?php $supplierOptionName = trim((string) ($supplierOption['name'] ?? '')); ?>
                <?php if ($supplierOptionName !== ''): ?>
                    <option value="<?= e($supplierOptionName) ?>" <?= $supplierOptionName === $activeSupplierName ? 'selected' : '' ?>><?= e($supplierOptionName) ?></option>
                <?php endif; ?>
            <?php endforeach; ?>
            <?php if ($activeSupplierName !== '' && ! in_array($activeSupplierName, $supplierOptionNames, true)): ?>
                <option value="<?= e($activeSupplierName) ?>" selected><?= e($activeSupplierName) ?></option>
            <?php endif; ?>
            <option value="__add_supplier__">+ Add new supplier...</option>
        </select></label>
        <label class="legacy-field legacy-field--ticket-type" data-air-only>
            <span>Ticket Type</span>
            <select name="ticket_type" data-ticket-field="ticket_type">
                <?php $ticketTypeValue = strtolower(trim((string) ($activeService['ticketType'] ?? 'international'))); ?>
                <?php if ($ticketTypeValue === '') { $ticketTypeValue = 'international'; } ?>
                <option value=""></option>
                <option value="local" <?= $ticketTypeValue === 'local' ? 'selected' : '' ?>>Local</option>
                <option value="international" <?= $ticketTypeValue === 'international' ? 'selected' : '' ?>>International</option>
            </select>
        </label>
        <label class="legacy-field legacy-field--class" data-air-only>
            <span>Class</span>
            <select name="ticket_class" data-ticket-field="class">
                <?php $ticketClassValue = strtolower(trim((string) ($activeService['class'] ?? 'economy'))); ?>
                <?php if ($ticketClassValue === '') { $ticketClassValue = 'economy'; } ?>
                <option value=""></option>
                <option value="economy" <?= $ticketClassValue === 'economy' ? 'selected' : '' ?>>Economy</option>
                <option value="business" <?= $ticketClassValue === 'business' ? 'selected' : '' ?>>Business</option>
                <option value="first" <?= $ticketClassValue === 'first' ? 'selected' : '' ?>>First</option>
            </select>
        </label>
        <label class="legacy-field legacy-field--xs" data-air-only><span>Conj.</span><input type="text" value="" readonly></label>
        <label class="legacy-field legacy-field--attach" data-air-only><span>Attach Last Ticket #</span><input type="text" value="" readonly></label>
        <label class="legacy-field legacy-field--place" data-air-only><span>Place of services (VAT)</span><input type="text" value="OTHER" readonly></label>
        <label class="legacy-field legacy-field--airline" data-air-only><span>Airline/Agent (CR)</span><input type="text" name="ticket_airline" data-ticket-field="airline" value="<?= e((string) ($activeService['airline'] ?? '')) ?>"></label>
       <label class="legacy-field legacy-field--sales" hidden aria-hidden="true"><span>Sales Person</span><input type="text" value="<?= e((string) ($user['username'] ?? $user['email'] ?? '')) ?>" readonly></label>

        <label class="legacy-field legacy-field--xs" data-air-only><span>XO</span><input type="text" value="" readonly></label>
        <label class="legacy-field legacy-field--date"><span>Validation Date</span><input type="date" name="due_date" data-service-field="due_date" value="<?= e((string) ($activeService['dueDate'] ?? '')) ?>"></label>
        <label class="legacy-field legacy-field--booking-ref"><span>Booking Ref.</span><input type="text" data-service-field="lineNumber" value="<?= e((string) ($activeService['lineNumber'] ?? 'SV-DRAFT')) ?>" readonly></label>
        <label class="legacy-field legacy-field--status"><span>Status</span><select name="service_status" data-service-field="status"><?php foreach ($serviceStatusOptions as $statusOption): ?><option value="<?= e($statusOption) ?>" <?= $statusOption === ($activeService['status'] ?? 'Open') ? 'selected' : '' ?>><?= e(substr($statusOption, 0, 3)) ?></option><?php endforeach; ?></select></label>
        <label class="legacy-field legacy-field--xs" hidden aria-hidden="true"><span>Curr.</span><select data-service-field="currency-mirror" tabindex="-1"><?php foreach (['PKR', 'AED', 'USD'] as $currencyOption): ?><option value="<?= e($currencyOption) ?>" <?= $currencyOption === (string) ($activeService['currency'] ?? $invoiceCurrency) ? 'selected' : '' ?>><?= e($currencyOption) ?></option><?php endforeach; ?></select></label>
        <label class="legacy-field legacy-field--date"><span>Dep. Date</span><input type="date" name="ticket_departure_date" data-ticket-field="departure_date" value="<?= e((string) ($activeService['departureDate'] ?? '')) ?>"></label>
        <label class="legacy-field legacy-field--route"><span>Route</span><input type="text" value="<?= e($routeLabel) ?>" maxlength="11" spellcheck="false" autocomplete="off" data-ticket-route-display></label>
        <label class="legacy-field legacy-field--nationality"><span>Nationality</span><input type="text" value="<?= e((string) ($leadTraveler['nationality'] ?? '')) ?>" readonly></label>
                 <label class="legacy-field legacy-field--xs" data-air-only><span>BSP</span><input type="text" value="N" readonly></label>
        <label class="legacy-field legacy-field--sector"><span>Sector and Description</span><input type="text" name="remarks" data-service-field="remarks" value="<?= e($sectorDescription) ?>"></label>
        <div class="legacy-service-subtype-panel" data-service-subtype-panel="visa" hidden>
            <label class="legacy-field"><span>Country</span><input type="text" name="visa_country" data-subtype-field="visaCountry" value="<?= e((string) ($activeService['visaCountry'] ?? '')) ?>"></label>
            <label class="legacy-field"><span>Visa Type</span><input type="text" name="visa_type" data-subtype-field="visaType" value="<?= e((string) ($activeService['visaType'] ?? '')) ?>"></label>
            <label class="legacy-field"><span>Application Ref</span><input type="text" name="visa_application_reference" data-subtype-field="visaApplicationReference" value="<?= e((string) ($activeService['visaApplicationReference'] ?? '')) ?>"></label>
            <label class="legacy-field"><span>Passport No.</span><input type="text" name="visa_passport_number" data-subtype-field="visaPassportNumber" value="<?= e((string) ($activeService['visaPassportNumber'] ?? '')) ?>"></label>
            <label class="legacy-field"><span>Submitted</span><input type="date" name="visa_submission_date" data-subtype-field="visaSubmissionDate" value="<?= e((string) ($activeService['visaSubmissionDate'] ?? '')) ?>"></label>
            <label class="legacy-field"><span>Issued</span><input type="date" name="visa_issue_date" data-subtype-field="visaIssueDate" value="<?= e((string) ($activeService['visaIssueDate'] ?? '')) ?>"></label>
            <label class="legacy-field"><span>Expiry</span><input type="date" name="visa_expiry_date" data-subtype-field="visaExpiryDate" value="<?= e((string) ($activeService['visaExpiryDate'] ?? '')) ?>"></label>
            <label class="legacy-field"><span>Status</span><input type="text" name="visa_status" data-subtype-field="visaStatus" value="<?= e((string) ($activeService['visaStatus'] ?? '')) ?>"></label>
            <label class="legacy-field legacy-field--wide"><span>Visa Notes</span><input type="text" name="visa_remarks" data-subtype-field="visaRemarks" value="<?= e((string) ($activeService['visaRemarks'] ?? '')) ?>"></label>
        </div>
        <div class="legacy-service-subtype-panel" data-service-subtype-panel="umrah" hidden>
            <label class="legacy-field"><span>Package</span><input type="text" name="umrah_package_name" data-subtype-field="umrahPackageName" value="<?= e((string) ($activeService['umrahPackageName'] ?? '')) ?>"></label>
            <label class="legacy-field"><span>MOFA Ref</span><input type="text" name="umrah_mofa_reference" data-subtype-field="umrahMofaReference" value="<?= e((string) ($activeService['umrahMofaReference'] ?? '')) ?>"></label>
            <label class="legacy-field"><span>Departure</span><input type="date" name="umrah_departure_date" data-subtype-field="umrahDepartureDate" value="<?= e((string) ($activeService['umrahDepartureDate'] ?? '')) ?>"></label>
            <label class="legacy-field"><span>Return</span><input type="date" name="umrah_return_date" data-subtype-field="umrahReturnDate" value="<?= e((string) ($activeService['umrahReturnDate'] ?? '')) ?>"></label>
            <label class="legacy-field"><span>Hotel</span><input type="text" name="umrah_hotel_name" data-subtype-field="umrahHotelName" value="<?= e((string) ($activeService['umrahHotelName'] ?? '')) ?>"></label>
            <label class="legacy-field"><span>Transport</span><input type="text" name="umrah_transport_notes" data-subtype-field="umrahTransportNotes" value="<?= e((string) ($activeService['umrahTransportNotes'] ?? '')) ?>"></label>
            <label class="legacy-field legacy-field--wide"><span>Umrah Notes</span><input type="text" name="umrah_remarks" data-subtype-field="umrahRemarks" value="<?= e((string) ($activeService['umrahRemarks'] ?? '')) ?>"></label>
        </div>
        <div class="legacy-service-subtype-panel" data-service-subtype-panel="hotel" hidden>
            <label class="legacy-field"><span>Hotel</span><input type="text" name="hotel_name" data-subtype-field="hotelName" value="<?= e((string) ($activeService['hotelName'] ?? '')) ?>"></label>
            <label class="legacy-field"><span>City</span><input type="text" name="hotel_city" data-subtype-field="hotelCity" value="<?= e((string) ($activeService['hotelCity'] ?? '')) ?>"></label>
            <label class="legacy-field"><span>Confirm No.</span><input type="text" name="hotel_confirmation_number" data-subtype-field="hotelConfirmationNumber" value="<?= e((string) ($activeService['hotelConfirmationNumber'] ?? '')) ?>"></label>
            <label class="legacy-field"><span>Check In</span><input type="date" name="hotel_check_in_date" data-subtype-field="hotelCheckInDate" value="<?= e((string) ($activeService['hotelCheckInDate'] ?? '')) ?>"></label>
            <label class="legacy-field"><span>Check Out</span><input type="date" name="hotel_check_out_date" data-subtype-field="hotelCheckOutDate" value="<?= e((string) ($activeService['hotelCheckOutDate'] ?? '')) ?>"></label>
            <label class="legacy-field"><span>Room Type</span><input type="text" name="hotel_room_type" data-subtype-field="hotelRoomType" value="<?= e((string) ($activeService['hotelRoomType'] ?? '')) ?>"></label>
            <label class="legacy-field"><span>Guests</span><input type="number" min="0" step="1" name="hotel_guest_count" data-subtype-field="hotelGuestCount" value="<?= e((string) ($activeService['hotelGuestCount'] ?? 0)) ?>"></label>
            <label class="legacy-field legacy-field--wide"><span>Hotel Notes</span><input type="text" name="hotel_remarks" data-subtype-field="hotelRemarks" value="<?= e((string) ($activeService['hotelRemarks'] ?? '')) ?>"></label>
        </div>
        <div class="legacy-service-subtype-panel" data-service-subtype-panel="transport" hidden>
            <label class="legacy-field"><span>Mode</span><input type="text" name="transport_mode" data-subtype-field="transportMode" value="<?= e((string) ($activeService['transportMode'] ?? '')) ?>"></label>
            <label class="legacy-field"><span>Vehicle</span><input type="text" name="transport_vehicle_type" data-subtype-field="transportVehicleType" value="<?= e((string) ($activeService['transportVehicleType'] ?? '')) ?>"></label>
            <label class="legacy-field"><span>Pickup Date</span><input type="date" name="transport_pickup_date" data-subtype-field="transportPickupDate" value="<?= e((string) ($activeService['transportPickupDate'] ?? '')) ?>"></label>
            <label class="legacy-field"><span>Pickup</span><input type="text" name="transport_pickup_location" data-subtype-field="transportPickupLocation" value="<?= e((string) ($activeService['transportPickupLocation'] ?? '')) ?>"></label>
            <label class="legacy-field"><span>Dropoff</span><input type="text" name="transport_dropoff_location" data-subtype-field="transportDropoffLocation" value="<?= e((string) ($activeService['transportDropoffLocation'] ?? '')) ?>"></label>
            <label class="legacy-field"><span>Driver</span><input type="text" name="transport_driver_detail" data-subtype-field="transportDriverDetail" value="<?= e((string) ($activeService['transportDriverDetail'] ?? '')) ?>"></label>
            <label class="legacy-field legacy-field--wide"><span>Route Notes</span><input type="text" name="transport_route_notes" data-subtype-field="transportRouteNotes" value="<?= e((string) ($activeService['transportRouteNotes'] ?? '')) ?>"></label>
            <label class="legacy-field legacy-field--wide"><span>Transport Notes</span><input type="text" name="transport_remarks" data-subtype-field="transportRemarks" value="<?= e((string) ($activeService['transportRemarks'] ?? '')) ?>"></label>
        </div>
        <div class="legacy-service-subtype-panel" data-service-subtype-panel="tourism" hidden>
            <label class="legacy-field"><span>Tour Name</span><input type="text" name="tour_name" data-subtype-field="tourName" value="<?= e((string) ($activeService['tourName'] ?? '')) ?>"></label>
            <label class="legacy-field"><span>Destination</span><input type="text" name="tour_destination" data-subtype-field="tourDestination" value="<?= e((string) ($activeService['tourDestination'] ?? '')) ?>"></label>
            <label class="legacy-field"><span>Confirm No.</span><input type="text" name="tour_confirmation_number" data-subtype-field="tourConfirmationNumber" value="<?= e((string) ($activeService['tourConfirmationNumber'] ?? '')) ?>"></label>
            <label class="legacy-field"><span>Start</span><input type="date" name="tour_start_date" data-subtype-field="tourStartDate" value="<?= e((string) ($activeService['tourStartDate'] ?? '')) ?>"></label>
            <label class="legacy-field"><span>End</span><input type="date" name="tour_end_date" data-subtype-field="tourEndDate" value="<?= e((string) ($activeService['tourEndDate'] ?? '')) ?>"></label>
            <label class="legacy-field legacy-field--wide"><span>Inclusions</span><input type="text" name="tour_inclusions" data-subtype-field="tourInclusions" value="<?= e((string) ($activeService['tourInclusions'] ?? '')) ?>"></label>
            <label class="legacy-field legacy-field--wide"><span>Tour Notes</span><input type="text" name="tour_remarks" data-subtype-field="tourRemarks" value="<?= e((string) ($activeService['tourRemarks'] ?? '')) ?>"></label>
        </div>
        <div class="legacy-service-subtype-panel" data-service-subtype-panel="other" hidden>
            <label class="legacy-field"><span>Service Label</span><input type="text" name="other_label" data-subtype-field="otherLabel" value="<?= e((string) ($activeService['otherLabel'] ?? '')) ?>"></label>
            <label class="legacy-field"><span>Reference</span><input type="text" name="other_reference_number" data-subtype-field="otherReferenceNumber" value="<?= e((string) ($activeService['otherReferenceNumber'] ?? '')) ?>"></label>
            <label class="legacy-field"><span>Service Date</span><input type="date" name="other_service_date" data-subtype-field="otherServiceDate" value="<?= e((string) ($activeService['otherServiceDate'] ?? '')) ?>"></label>
            <label class="legacy-field"><span>Provider</span><input type="text" name="other_provider_name" data-subtype-field="otherProviderName" value="<?= e((string) ($activeService['otherProviderName'] ?? '')) ?>"></label>
            <label class="legacy-field legacy-field--wide"><span>Other Notes</span><input type="text" name="other_remarks" data-subtype-field="otherRemarks" value="<?= e((string) ($activeService['otherRemarks'] ?? '')) ?>"></label>
        </div>
        <div class="legacy-inline-note legacy-inline-note--supplier-advance" data-supplier-advance-note hidden>
            <strong data-supplier-advance-summary></strong>
            <span data-supplier-advance-message></span>
        </div>
        <input type="hidden" name="supplier_advance_fx_use" value="" data-supplier-advance-fx-use>
        <input type="hidden" name="supplier_advance_fx_advance_id" value="" data-supplier-advance-fx-advance-id>
        <input type="hidden" name="supplier_advance_fx_rate" value="" data-supplier-advance-fx-rate>
        <input type="hidden" name="supplier_advance_fx_rate_date" value="<?= e(date('Y-m-d')) ?>" data-supplier-advance-fx-rate-date>
        <input type="hidden" name="ticket_return_date" data-ticket-field="return_date" value="<?= e((string) ($activeService['returnDate'] ?? '')) ?>">
        <input type="hidden" name="ticket_sector_from" data-ticket-field="sector_from" value="<?= e((string) ($activeService['sectorFrom'] ?? '')) ?>">
        <input type="hidden" name="ticket_sector_to" data-ticket-field="sector_to" value="<?= e((string) ($activeService['sectorTo'] ?? '')) ?>">
        <input type="hidden" name="ticket_tax" data-ticket-metric="tax" value="<?= e((string) ($activeService['ticketTax'] ?? 0)) ?>">
        <input type="hidden" name="ticket_vat" data-ticket-metric="vat" value="<?= e((string) ($activeService['ticketVat'] ?? 0)) ?>">
        <input type="hidden" name="ticket_commission" data-ticket-metric="commission" value="<?= e((string) ($activeService['ticketCommission'] ?? 0)) ?>">
        <input type="hidden" name="ticket_remarks" data-ticket-field="ticket_remarks" value="<?= e((string) ($activeService['ticketRemarks'] ?? '')) ?>">

        <?php /*
Manual service save button disabled because service saving is now handled automatically.
Kept here in case manual service save is needed again later.

<button class="legacy-service-save btn btn-primary btn-sm" type="submit" data-service-submit data-manual-service-save <?= (int) ($activeService['serviceId'] ?? 0) > 0 ? '' : 'hidden aria-hidden="true" tabindex="-1"' ?>>Update Service</button>
*/ ?>    </form>

    <?php if ($canPostServiceEvents): ?>
    <?php
        $activeServiceId = (int) ($activeService['serviceId'] ?? 0);
        $activeServiceStatus = str_replace(' ', '_', strtolower(trim((string) ($activeService['status'] ?? ''))));
        $activeServiceHasCancellationEvent = (bool) ($activeService['hasCancellationEvent'] ?? false);
        $activeServiceType = strtolower(trim((string) ($activeService['type'] ?? '')));
        $serviceEventsAllowedInThisSession = ! $workspaceIsNew && (int) ($workspaceBooking['id'] ?? 0) > 0;
        $activeServiceIsCancelled = $activeServiceStatus === 'cancelled' || $activeServiceHasCancellationEvent;
        $showCancelAction = $serviceEventsAllowedInThisSession && $activeServiceId > 0 && ! $activeServiceIsCancelled;
        $showRefundAction = $serviceEventsAllowedInThisSession && $activeServiceId > 0;
        $showSettlementAction = $serviceEventsAllowedInThisSession && $activeServiceId > 0 && $activeServiceIsCancelled;
        $showReissueAction = $serviceEventsAllowedInThisSession && $activeServiceId > 0 && $activeServiceType === 'air ticket';
        $showFinancialCorrectionAction = $serviceEventsAllowedInThisSession && $activeServiceId > 0;
        $financialCorrectionCostLabel = $activeServiceType === 'air ticket' ? 'Mkt. Fare' : 'Cost';
        $financialCorrectionCostValue = $activeServiceType === 'air ticket'
            ? (float) ($activeService['salePrice'] ?? 0)
            : (float) ($activeService['purchaseCost'] ?? 0);
        $financialCorrectionCustomerValue = (float) ($activeService['finalSalePrice'] ?? 0);
        $financialCorrectionServiceChargeValue = (float) ($activeService['serviceCharge'] ?? 0);
        $financialCorrectionDiscountValue = (float) ($activeService['discountAmount'] ?? 0);
        $financialCorrectionVatValue = (float) ($activeService['vat'] ?? 0);
        $financialCorrectionTaxTotal = $serviceTaxTotal($activeService);
        $financialCorrectionPayableAmount = $activeServiceType === 'air ticket'
            ? round($financialCorrectionCostValue + $financialCorrectionTaxTotal, 2)
            : round($financialCorrectionCostValue, 2);
        $financialCorrectionLossAmount = max(0, round($financialCorrectionPayableAmount - $financialCorrectionCustomerValue, 2));
        $financialCorrectionHasLoss = $financialCorrectionLossAmount > 0.005;
        $settlementCustomerPenaltyValue = (float) ($activeService['latestCancelCustomerPenaltyAmount'] ?? 0);
        $settlementFinanciallySettled = (bool) ($activeService['latestCancelFinanciallySettled'] ?? false);
        $settlementSupplierPenaltyValue = (float) ($activeService['latestCancelSupplierPenaltyAmount'] ?? 0);
        $settlementExpectedSupplierRefundSavedValue = (float) ($activeService['latestCancelExpectedSupplierRefundAmount'] ?? 0);
        $settlementExpectedSupplierRefundValue = $settlementExpectedSupplierRefundSavedValue;
        if ($settlementExpectedSupplierRefundValue <= 0.005) {
            $settlementExpectedSupplierRefundValue = max((float) ($activeService['purchaseCost'] ?? 0) - $settlementSupplierPenaltyValue, 0);
        }
        $settlementCustomerFinalChargeValue = (float) ($activeService['latestCancelCustomerFinalChargeAmount'] ?? 0);
        $settlementReleasedCustomerCreditValue = (float) ($activeService['latestCancelReleasedCustomerCreditAmount'] ?? 0);
        $settlementReleasedSupplierCreditValue = (float) ($activeService['latestCancelReleasedSupplierCreditAmount'] ?? 0);
        $settlementRemainingCustomerRefundCreditValue = (float) ($activeService['customerRefundableCreditAmount'] ?? 0);
        $settlementRemainingSupplierRefundCreditValue = (float) ($activeService['supplierRefundableCreditAmount'] ?? 0);
        $latestCustomerRefundAmountOnlyValue = (float) ($activeService['latestCustomerRefundAmountOnly'] ?? 0);
        $latestSupplierRefundAmountOnlyValue = (float) ($activeService['latestSupplierRefundAmountOnly'] ?? 0);
        $settlementDisplayCustomerReleasedCreditValue = max(
            $settlementReleasedCustomerCreditValue,
            $latestCustomerRefundAmountOnlyValue + $settlementRemainingCustomerRefundCreditValue
        );
        $settlementDisplaySupplierReleasedCreditValue = max(
            $settlementReleasedSupplierCreditValue,
            $latestSupplierRefundAmountOnlyValue + $settlementRemainingSupplierRefundCreditValue
        );
        $formatSettlementAmount = static function (float $amount) use ($invoiceCurrency): string {
            return $invoiceCurrency . ' ' . number_format(round($amount, 0), 0);
        };
        $settlementReasonValue = (string) ($activeService['latestCancelReason'] ?? '');
        $settlementNotesValue = (string) ($activeService['latestCancelNotes'] ?? '');
        $settlementEventDateValue = (string) ($activeService['latestCancelEventDate'] ?? '');
        if ($settlementEventDateValue === '') {
            $settlementEventDateValue = date('Y-m-d');
        }
        $settlementSummaryVisible = $activeServiceHasCancellationEvent
            && (
                $settlementFinanciallySettled
                || $settlementCustomerPenaltyValue > 0.005
                || $settlementExpectedSupplierRefundSavedValue > 0.005
                || $settlementSupplierPenaltyValue > 0.005
                || $settlementReleasedCustomerCreditValue > 0.005
                || $settlementReleasedSupplierCreditValue > 0.005
            );
        $settlementSummaryText = sprintf(
            'Customer penalty: %s. Expected supplier refund: %s. Supplier penalty: %s. Customer refund released: %s.',
            $formatSettlementAmount($settlementCustomerPenaltyValue),
            $formatSettlementAmount($settlementExpectedSupplierRefundValue),
            $formatSettlementAmount($settlementSupplierPenaltyValue),
            $formatSettlementAmount($settlementDisplayCustomerReleasedCreditValue)
        );
        $refundProgressSummaryParts = [];
        if ($settlementRemainingCustomerRefundCreditValue > 0.005) {
            $refundProgressSummaryParts[] = 'Customer refund available now: ' . $formatSettlementAmount($settlementRemainingCustomerRefundCreditValue);
        } elseif ($settlementDisplayCustomerReleasedCreditValue > 0.005) {
            $refundProgressSummaryParts[] = 'Customer refund released: ' . $formatSettlementAmount($settlementDisplayCustomerReleasedCreditValue);
        }
        if ($latestCustomerRefundAmountOnlyValue > 0.005) {
            $refundProgressSummaryParts[] = 'Customer refund already posted: ' . $formatSettlementAmount($latestCustomerRefundAmountOnlyValue);
        }
        if ($settlementRemainingSupplierRefundCreditValue > 0.005) {
            $refundProgressSummaryParts[] = 'Supplier refund still expected: ' . $formatSettlementAmount($settlementRemainingSupplierRefundCreditValue);
        }
        if ($latestSupplierRefundAmountOnlyValue > 0.005) {
            $refundProgressSummaryParts[] = 'Supplier refund already received: ' . $formatSettlementAmount($latestSupplierRefundAmountOnlyValue);
        }
        $refundProgressSummaryText = implode('. ', $refundProgressSummaryParts);
        if ($refundProgressSummaryText !== '') {
            $refundProgressSummaryText .= '.';
        }
        $latestCustomerRefundEventId = (int) ($activeService['latestCustomerRefundEventId'] ?? 0);
        $latestSupplierRefundEventId = (int) ($activeService['latestSupplierRefundEventId'] ?? 0);
        $showRefundReverseForm = $latestCustomerRefundEventId > 0 || $latestSupplierRefundEventId > 0;
        $hasPostedRefundActivity = $latestCustomerRefundAmountOnlyValue > 0.005 || $latestSupplierRefundAmountOnlyValue > 0.005;
        $cancellationCustomerRefundPending = $settlementRemainingCustomerRefundCreditValue > 0.005;
        $cancellationSupplierRefundPending = $settlementRemainingSupplierRefundCreditValue > 0.005;
        $supplierRefundAlreadyReceived = $latestSupplierRefundAmountOnlyValue > 0.005 && ! $cancellationSupplierRefundPending;
        $cancellationFinancialFollowUpComplete = $activeServiceIsCancelled
            && $settlementSummaryVisible
            && ! $cancellationCustomerRefundPending
            && ! $cancellationSupplierRefundPending;
        $showPostedRefundSummary = $cancellationFinancialFollowUpComplete && $hasPostedRefundActivity;
        $showSettlementEditForm = false;
        $showSettlementForm = $showSettlementAction && ! $settlementSummaryVisible;
        $showSettlementReverseForm = false;
        $showSettlementCorrectionForm = $settlementSummaryVisible && ! $showRefundReverseForm;
        $showSettlementCorrectionReverseForm = $settlementSummaryVisible && ! $showRefundReverseForm;
        $showRefundForm = $showRefundAction && (
            ! $activeServiceIsCancelled
            || $cancellationCustomerRefundPending
            || $cancellationSupplierRefundPending
            || (
                ! $hasPostedRefundActivity
                && ($settlementFinanciallySettled || $settlementSummaryVisible)
            )
        );
        $showReopenCancelForm = $showSettlementAction && ! $settlementSummaryVisible && ! $showRefundReverseForm;
        if ($cancellationFinancialFollowUpComplete) {
            $cancelledServiceNotice = $showRefundReverseForm
                ? 'This service is already cancelled and refunded.'
                : 'This service is already cancelled and settled. Refund follow-up is complete.';
        } else {
            $cancelledServiceNotice = $settlementSummaryVisible
                ? 'This service is already cancelled. Settlement is already recorded. Continue with refund follow-up below.'
                : 'This service is already cancelled. Use settlement or refund only if financial follow-up is still needed.';
        }
    ?>
    <section class="customer-picker-modal" data-service-edit-booking-modal hidden aria-hidden="true">
        <div class="customer-picker-modal__backdrop" data-service-edit-booking-close></div>
        <div class="customer-picker-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="service-edit-booking-title" style="max-width:1380px;">
            <header class="customer-picker-modal__header">
                <div>
                    <strong id="service-edit-booking-title">Edit Booking</strong>
                    <span>Use this popup for the full booking workflow: cancel, settlement, refund, reissue, and booking financial edits.</span>
                </div>
                <button class="btn btn-sm" type="button" data-service-edit-booking-close>Close</button>
            </header>
            <div class="customer-picker-modal__body">
                <div class="legacy-service-event-note legacy-service-event-note--cancelled" data-service-cancelled-note <?= $activeServiceIsCancelled ? '' : 'hidden' ?>>
                    <?= e($cancelledServiceNotice) ?>
                </div>
                <?php if ($settlementSummaryVisible && $refundProgressSummaryText !== '' && ! $showPostedRefundSummary): ?>
                    <div class="legacy-service-event-note">
                        <?= e($refundProgressSummaryText) ?>
                    </div>
                <?php endif; ?>
                <?php if ($showPostedRefundSummary): ?>
                    <div class="legacy-service-event-note">
                        Posted refund summary:
                        Customer already received refund of <strong><?= e($formatSettlementAmount($latestCustomerRefundAmountOnlyValue)) ?></strong>,
                        supplier refund received <strong><?= e($formatSettlementAmount($latestSupplierRefundAmountOnlyValue)) ?></strong>.
                        Use <strong>Edit Penalty / Refund</strong> only if one of these posted values needs correction.
                    </div>
                <?php endif; ?>
                <form class="legacy-service-event-bar" method="post" action="<?= e(url('/workspace/services/cancel')) ?>" data-service-event-bar="cancel" <?= $showCancelAction ? '' : 'hidden' ?>>
                    <?= \App\Helpers\Csrf::input() ?>
                    <input type="hidden" name="booking_id" value="<?= e((string) $workspaceBooking['id']) ?>">
                    <input type="hidden" name="service_id" value="<?= e((string) ($activeService['serviceId'] ?? 0)) ?>" data-service-cancel-id>
                    <label class="legacy-service-event-field"><span>Date</span><input type="date" name="cancel_event_date" value="<?= e(date('Y-m-d')) ?>" aria-label="Cancellation date"></label>
                    <label class="legacy-service-event-field"><span>Reason</span><input type="text" name="cancel_reason" value="" placeholder="Cancellation reason" minlength="5" maxlength="1000" required></label>
                    <label class="legacy-service-event-field"><span>Note</span><input type="text" name="cancel_notes" value="" placeholder="Optional note" maxlength="4000"></label>
                    <button class="btn btn-sm legacy-service-event-action" type="submit" data-service-cancel-button <?= $showCancelAction ? '' : 'disabled' ?>>Cancel Service</button>
                </form>
                <form class="legacy-service-event-bar legacy-service-event-bar--settlement" method="post" action="<?= e(url('/workspace/services/cancellation-financials')) ?>" data-service-event-bar="settlement" <?= $showSettlementForm ? '' : 'hidden' ?>>
                    <?= \App\Helpers\Csrf::input() ?>
                    <input type="hidden" name="booking_id" value="<?= e((string) $workspaceBooking['id']) ?>">
                    <input type="hidden" name="service_id" value="<?= e((string) ($activeService['serviceId'] ?? 0)) ?>" data-service-settlement-id>
                    <input type="hidden" name="settlement_edit_mode" value="<?= $showSettlementEditForm ? '1' : '0' ?>">
                    <div class="legacy-service-event-note">Already received on this booking: <strong><?= e($settlementPaidHintDisplay) ?></strong></div>
                    <label class="legacy-service-event-field"><span>Date</span><input type="date" name="settlement_event_date" value="<?= e($settlementEventDateValue) ?>" aria-label="Settlement date"></label>
                    <label class="legacy-service-event-field legacy-service-event-field--customer-penalty"><span>Customer Penalty</span><input type="number" name="customer_penalty_amount" value="<?= e(number_format(round($settlementCustomerPenaltyValue, 0), 0, '.', '')) ?>" min="0" step="0.01" aria-label="Customer penalty amount"></label>
                    <label class="legacy-service-event-field legacy-service-event-field--expected-supplier-refund"><span>Expected Supplier Refund</span><input type="number" name="expected_supplier_refund_amount" value="<?= e(number_format(round($settlementExpectedSupplierRefundValue, 0), 0, '.', '')) ?>" min="0" step="0.01" aria-label="Expected supplier refund amount"></label>
                    <label class="legacy-service-event-field legacy-service-event-field--supplier-penalty"><span>Supplier Penalty</span><input type="number" name="supplier_penalty_amount" value="<?= e(number_format(round($settlementSupplierPenaltyValue, 0), 0, '.', '')) ?>" min="0" step="0.01" aria-label="Calculated supplier penalty amount" readonly></label>
                    <label class="legacy-service-event-field"><span>Reason</span><input type="text" name="settlement_reason" value="<?= e($settlementReasonValue) ?>" placeholder="Settlement reason" minlength="5" maxlength="1000" data-service-settlement-reason></label>
                    <button class="btn btn-sm legacy-service-event-action" type="submit" data-service-settlement-button <?= $showSettlementForm ? '' : 'disabled' ?>>Settle Cancel</button>
                </form>
                <form class="legacy-service-event-bar legacy-service-event-bar--refund" method="post" action="<?= e(url('/workspace/services/refund')) ?>" data-service-event-bar="refund" <?= $showRefundForm ? '' : 'hidden' ?>>
                    <?= \App\Helpers\Csrf::input() ?>
                    <input type="hidden" name="booking_id" value="<?= e((string) $workspaceBooking['id']) ?>">
                    <input type="hidden" name="service_id" value="<?= e((string) ($activeService['serviceId'] ?? 0)) ?>" data-service-refund-id>
                    <input type="hidden" name="refund_branch_id" value="<?= e((string) $workspaceBooking['branchId']) ?>" data-service-refund-branch-id>
                    <input type="hidden" name="refund_currency" value="<?= e((string) ($activeService['currency'] ?? $invoiceCurrency)) ?>" data-service-refund-currency>
                    <input type="hidden" name="refund_edit_scope" value="" data-service-refund-edit-scope>
                    <div class="legacy-service-event-bar__row legacy-service-event-bar__row--refund-customer">
                        <label class="legacy-service-event-field legacy-service-event-field--refund-date"><span>Date</span><input type="date" name="refund_event_date" value="<?= e(date('Y-m-d')) ?>" aria-label="Refund date"></label>
                        <label class="legacy-service-event-field legacy-service-event-field--customer-refund"><span>Customer Refund</span><input type="number" name="customer_refund_amount" value="0" min="0" step="1" aria-label="Customer refund amount"></label>
                        <label class="legacy-service-event-field legacy-service-event-field--customer-method"><span>Customer Method</span><select name="refund_payment_method" aria-label="Customer refund payment method" data-service-refund-method>
                            <?php foreach ($refundPaymentMethodOptions as $refundMethodValue => $refundMethodLabel): ?>
                                <option value="<?= e($refundMethodValue) ?>" <?= (string) $refundMethodValue === 'cash' ? 'selected' : '' ?>><?= e($refundMethodLabel) ?></option>
                            <?php endforeach; ?>
                        </select></label>
                        <label class="legacy-service-event-field legacy-service-event-field--customer-account" data-service-refund-treasury-row><span>Paid From</span><select name="refund_treasury_account_id" data-service-refund-treasury-select aria-label="Customer refund paid from account"><option value="">Select refund account</option></select></label>
                        <label class="legacy-service-event-field legacy-service-event-field--refund-reason" data-service-refund-reason><span>Reason</span><input type="text" name="refund_reason" value="" placeholder="Refund reason / correction reason" minlength="5" maxlength="1000" required></label>
                    </div>
                    <div class="legacy-service-event-bar__row legacy-service-event-bar__row--refund-supplier">
                        <label class="legacy-service-event-field legacy-service-event-field--supplier-refund"><span>Supplier Refund Received</span><input type="number" name="supplier_refund_amount" value="0" min="0" step="1" aria-label="Supplier refund received" <?= $supplierRefundAlreadyReceived ? 'readonly' : '' ?>></label>
                        <label class="legacy-service-event-field legacy-service-event-field--supplier-method"><span>Supplier Method</span><select name="supplier_refund_payment_method" aria-label="Supplier refund received method" data-service-supplier-refund-method <?= $supplierRefundAlreadyReceived ? 'disabled' : '' ?>>
                            <?php foreach ($refundPaymentMethodOptions as $refundMethodValue => $refundMethodLabel): ?>
                                <option value="<?= e($refundMethodValue) ?>" <?= (string) $refundMethodValue === 'cash' ? 'selected' : '' ?>><?= e($refundMethodLabel) ?></option>
                            <?php endforeach; ?>
                        </select></label>
                        <label class="legacy-service-event-field legacy-service-event-field--supplier-account" data-service-supplier-refund-treasury-row><span>Received In</span><select name="supplier_refund_treasury_account_id" data-service-supplier-refund-treasury-select aria-label="Supplier refund received in account" <?= $supplierRefundAlreadyReceived ? 'disabled' : '' ?>><option value="">Select receiving account</option></select></label>
                        <button class="btn btn-sm legacy-service-event-action" type="submit" data-service-refund-button data-service-refund-submit onclick="this.form.querySelector('[data-service-refund-edit-scope]').value='';" <?= (int) ($activeService['serviceId'] ?? 0) > 0 ? '' : 'disabled' ?>>Post Refund</button>
                    </div>
                    <label class="legacy-service-event-field" data-service-refund-destination-row hidden><span>Customer Bank</span><input type="text" name="customer_bank_name" value="" maxlength="190" placeholder="Customer bank name" aria-label="Customer bank name"></label>
                    <label class="legacy-service-event-field" data-service-refund-destination-row hidden><span>Account Title</span><input type="text" name="customer_bank_account_title" value="" maxlength="190" placeholder="Account title" aria-label="Customer bank account title"></label>
                    <label class="legacy-service-event-field" data-service-refund-destination-row hidden><span>Account No.</span><input type="text" name="customer_bank_account_no" value="" maxlength="120" placeholder="Account no." aria-label="Customer bank account number"></label>
                    <label class="legacy-service-event-field" data-service-refund-destination-row hidden><span>IBAN</span><input type="text" name="customer_bank_iban" value="" maxlength="120" placeholder="IBAN" aria-label="Customer bank IBAN"></label>
                    <label class="legacy-service-event-field" data-service-refund-destination-row hidden><span>Transaction ID / Ref.</span><input type="text" name="transfer_reference" value="" maxlength="190" placeholder="Bank transaction ID, cheque no., or transfer reference" aria-label="Refund transaction ID or transfer reference"></label>
                    <label class="legacy-service-event-field" data-service-refund-destination-row hidden><span>Charges</span><input type="number" name="refund_charges" value="0" min="0" step="1" aria-label="Refund transfer charges"></label>
                </form>
                <form class="legacy-service-event-bar legacy-service-event-bar--refund-reverse" method="post" action="<?= e(url('/workspace/services/cancel/reopen')) ?>" data-service-event-bar="cancel-reopen" <?= $showReopenCancelForm ? '' : 'hidden' ?>>
                    <?= \App\Helpers\Csrf::input() ?>
                    <input type="hidden" name="booking_id" value="<?= e((string) $workspaceBooking['id']) ?>">
                    <input type="hidden" name="service_id" value="<?= e((string) ($activeService['serviceId'] ?? 0)) ?>">
                    <div class="legacy-service-event-note">Once no settlement or refund remains on this cancellation, you can reopen the service and post the correct cancel workflow again.</div>
                    <label class="legacy-service-event-field"><span>Reverse Cancel Reason</span><input type="text" name="cancel_reopen_reason" value="" placeholder="Why this cancel needs reversal" minlength="5" maxlength="255" required></label>
                    <button class="btn btn-sm legacy-service-event-action legacy-service-event-action--secondary" type="submit">Reopen Service</button>
                </form>
                <form class="legacy-service-event-bar legacy-service-event-bar--reissue" method="post" action="<?= e(url('/workspace/services/reissue')) ?>" data-service-event-bar="reissue" <?= $showReissueAction ? '' : 'hidden' ?>>
                    <?= \App\Helpers\Csrf::input() ?>
                    <input type="hidden" name="booking_id" value="<?= e((string) $workspaceBooking['id']) ?>">
                    <input type="hidden" name="service_id" value="<?= e((string) ($activeService['serviceId'] ?? 0)) ?>" data-service-reissue-id>
                    <label class="legacy-service-event-field"><span>Date</span><input type="date" name="reissue_event_date" value="<?= e(date('Y-m-d')) ?>" aria-label="Reissue date"></label>
                    <label class="legacy-service-event-field"><span>New Ticket</span><input type="text" name="new_ticket_number" value="" placeholder="New ticket no." maxlength="50" required></label>
                    <label class="legacy-service-event-field"><span>New PNR</span><input type="text" name="new_pnr" value="" placeholder="New PNR" maxlength="50"></label>
                    <label class="legacy-service-event-field"><span>Customer Extra</span><input type="number" name="fare_difference_amount" value="0" min="0" step="1" aria-label="Fare difference charged to customer"></label>
                    <label class="legacy-service-event-field"><span>Service Fee</span><input type="number" name="reissue_service_fee_amount" value="0" min="0" step="1" aria-label="Service fee"></label>
                    <label class="legacy-service-event-field"><span>Supplier Extra</span><input type="number" name="supplier_cost_difference_amount" value="0" min="0" step="1" aria-label="Supplier cost difference"></label>
                    <label class="legacy-service-event-field"><span>Reason</span><input type="text" name="reissue_reason" value="" placeholder="Reissue reason" minlength="5" maxlength="1000" required></label>
                    <button class="btn btn-sm legacy-service-event-action" type="submit" data-service-reissue-button <?= (int) ($activeService['serviceId'] ?? 0) > 0 && (string) ($activeService['type'] ?? '') === 'air ticket' ? '' : 'disabled' ?>>Reissue</button>
                </form>
                <form class="legacy-service-event-bar legacy-service-event-bar--financial-correction" method="post" action="<?= e(url('/workspace/services/financial-correction')) ?>" data-service-event-bar="financial-correction" data-financial-correction-service-type="<?= e((string) ($activeService['type'] ?? 'air ticket')) ?>" data-financial-correction-tax-total="<?= e(number_format(round($financialCorrectionTaxTotal, 0), 0, '.', '')) ?>" data-financial-correction-vat="<?= e(number_format(round($financialCorrectionVatValue, 0), 0, '.', '')) ?>" data-financial-correction-currency="<?= e((string) ($activeService['currency'] ?? $invoiceCurrency)) ?>" <?= $showFinancialCorrectionAction ? '' : 'hidden' ?>>
                    <?= \App\Helpers\Csrf::input() ?>
                    <input type="hidden" name="booking_id" value="<?= e((string) $workspaceBooking['id']) ?>">
                    <input type="hidden" name="service_id" value="<?= e((string) ($activeService['serviceId'] ?? 0)) ?>">
                    <div class="legacy-service-event-bar__row legacy-service-event-bar__row--financial-correction">
                        <label class="legacy-service-event-field legacy-service-event-field--date"><span>Date</span><input type="date" name="financial_correction_date" value="<?= e(date('Y-m-d')) ?>" aria-label="Financial correction date"></label>
                        <label class="legacy-service-event-field legacy-service-event-field--currency"><span>Invoice Curr.</span><select name="corrected_invoice_currency" aria-label="Corrected invoice currency"><?php foreach (['PKR', 'AED', 'USD'] as $currencyOption): ?><option value="<?= e($currencyOption) ?>" <?= $currencyOption === (string) ($activeService['currency'] ?? $invoiceCurrency) ? 'selected' : '' ?>><?= e($currencyOption) ?></option><?php endforeach; ?></select></label>
                        <label class="legacy-service-event-field legacy-service-event-field--currency"><span>Supplier Curr.</span><select name="corrected_cost_currency" aria-label="Corrected supplier cost currency"><?php foreach (['PKR', 'AED', 'USD'] as $currencyOption): ?><option value="<?= e($currencyOption) ?>" <?= $currencyOption === (string) ($activeService['costCurrency'] ?? $activeService['currency'] ?? $invoiceCurrency) ? 'selected' : '' ?>><?= e($currencyOption) ?></option><?php endforeach; ?></select></label>
                        <label class="legacy-service-event-field legacy-service-event-field--amount"><span><?= e($financialCorrectionCostLabel) ?></span><input type="number" name="corrected_cost_basis" value="<?= e(number_format(round($financialCorrectionCostValue, 0), 0, '.', '')) ?>" min="0" step="1" aria-label="<?= e($financialCorrectionCostLabel) ?>"></label>
                        <label class="legacy-service-event-field legacy-service-event-field--amount"><span>Serv.Amount</span><input type="number" name="corrected_service_charge" value="<?= e(number_format(round($financialCorrectionServiceChargeValue, 0), 0, '.', '')) ?>" min="0" step="1" aria-label="Service amount"></label>
                        <label class="legacy-service-event-field legacy-service-event-field--amount"><span>Discount</span><input type="number" name="corrected_discount_amount" value="<?= e(number_format(round($financialCorrectionDiscountValue, 0), 0, '.', '')) ?>" min="0" step="1" aria-label="Discount amount"></label>
                        <label class="legacy-service-event-field legacy-service-event-field--customer-total"><span>Customer Total</span><input type="number" name="corrected_final_sale_price" value="<?= e(number_format(round($financialCorrectionCustomerValue, 0), 0, '.', '')) ?>" min="0" step="1" aria-label="Customer total" readonly></label>
                        <label class="legacy-service-event-field legacy-service-event-field--loss"><span>Loss</span><input type="text" value="<?= e(number_format(round($financialCorrectionLossAmount, 0), 0, '.', '')) ?>" data-financial-correction-loss readonly></label>
                        <label class="legacy-service-event-field legacy-service-event-field--rate"><span>Exchange Rate</span><input type="number" name="corrected_pricing_exchange_rate" value="<?= e(number_format((float) ($activeService['pricingExchangeRate'] ?? 1), 8, '.', '')) ?>" min="0.00000001" step="0.00000001" aria-label="Corrected exchange rate"></label>
                        <label class="legacy-service-event-field legacy-service-event-field--date"><span>Rate Date</span><input type="date" name="corrected_pricing_rate_effective_date" value="<?= e((string) ($activeService['pricingRateEffectiveDate'] ?? date('Y-m-d'))) ?>" aria-label="Corrected exchange rate date"></label>
                    </div>
                    <div class="legacy-service-event-bar__row legacy-service-event-bar__row--financial-correction-actions">
                        <label class="legacy-service-event-field legacy-service-event-field--reason"><span>Reason</span><input type="text" name="financial_correction_reason" value="" placeholder="Supplier discount, wrong amount, sale correction..." minlength="5" maxlength="1000" required></label>
                        <button class="btn btn-sm legacy-service-event-action legacy-service-event-action--financial-correction" type="submit" <?= $showFinancialCorrectionAction ? '' : 'disabled' ?>>Save Invoice Edit</button>
                    </div>
                    <div class="legacy-service-event-note legacy-service-event-note--loss" data-financial-correction-loss-note <?= $financialCorrectionHasLoss ? '' : 'hidden' ?>>
                        Loss sale preview: <strong><?= e((string) ($activeService['currency'] ?? $invoiceCurrency) . ' ' . number_format(round($financialCorrectionLossAmount, 0), 0)) ?></strong>. Customer Total is below supplier payable, so this edit will record a loss instead of reducing <?= e($financialCorrectionCostLabel) ?> automatically.
                    </div>
                </form>
            </div>
        </div>
    </section>
    <section class="customer-picker-modal" data-service-penalty-refund-modal hidden aria-hidden="true">
        <div class="customer-picker-modal__backdrop" data-service-penalty-refund-close></div>
        <div class="customer-picker-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="service-penalty-refund-title" style="max-width:1520px;">
            <header class="customer-picker-modal__header">
                <div>
                    <strong id="service-penalty-refund-title">Edit Penalty / Refund</strong>
                    <span>Enter the correct penalty and refund values once; the system keeps the audit trail in the background.</span>
                </div>
                <button class="btn btn-sm" type="button" data-service-penalty-refund-close>Close</button>
            </header>
            <div class="customer-picker-modal__body">
                <div class="legacy-service-event-note legacy-service-event-note--cancelled" <?= $activeServiceIsCancelled ? '' : 'hidden' ?>>
                    <?= e($cancelledServiceNotice) ?>
                </div>
                <?php if (! $settlementSummaryVisible && ! $showRefundReverseForm): ?>
                    <div class="legacy-service-event-note">No posted settlement or refund exists yet for this service. Use <strong>Edit Booking</strong> for the main cancel / settle / refund workflow first.</div>
                <?php endif; ?>
                <form class="legacy-service-event-bar legacy-service-event-bar--settlement legacy-service-correction-bar" method="post" action="<?= e(url('/workspace/services/penalty-refund/correct')) ?>" data-service-correction-bar="settlement" data-service-correction-refund-form <?= ($showSettlementCorrectionForm || $showRefundReverseForm) ? '' : 'hidden' ?>>
                    <?= \App\Helpers\Csrf::input() ?>
                    <input type="hidden" name="booking_id" value="<?= e((string) $workspaceBooking['id']) ?>">
                    <input type="hidden" name="service_id" value="<?= e((string) ($activeService['serviceId'] ?? 0)) ?>" data-service-correction-settlement-id>
                    <input type="hidden" name="refund_branch_id" value="<?= e((string) $workspaceBooking['branchId']) ?>" data-service-correction-refund-branch-id>
                    <input type="hidden" name="refund_currency" value="<?= e((string) ($activeService['currency'] ?? $invoiceCurrency)) ?>" data-service-correction-refund-currency>
                    <label class="legacy-service-event-field legacy-service-event-field--refund-date"><span>Date</span><input type="date" name="correction_event_date" value="<?= e($settlementEventDateValue) ?>" aria-label="Correction date"></label>
                    <label class="legacy-service-event-field legacy-service-event-field--customer-penalty"><span>Customer Penalty</span><input type="number" name="customer_penalty_amount" value="<?= e(number_format(round($settlementCustomerPenaltyValue, 0), 0, '.', '')) ?>" min="0" step="0.01" aria-label="Customer penalty amount"></label>
                    <label class="legacy-service-event-field legacy-service-event-field--expected-supplier-refund"><span>Expected Supplier Refund</span><input type="number" name="expected_supplier_refund_amount" value="<?= e(number_format(round($settlementExpectedSupplierRefundValue, 0), 0, '.', '')) ?>" min="0" step="0.01" aria-label="Expected supplier refund amount"></label>
                    <label class="legacy-service-event-field legacy-service-event-field--supplier-penalty"><span>Supplier Penalty</span><input type="number" name="supplier_penalty_amount" value="<?= e(number_format(round($settlementSupplierPenaltyValue, 0), 0, '.', '')) ?>" min="0" step="0.01" aria-label="Calculated supplier penalty amount" readonly></label>
                    <label class="legacy-service-event-field legacy-service-event-field--customer-refund"><span>Customer Refund</span><input type="number" name="customer_refund_amount" value="<?= e(number_format(round($latestCustomerRefundAmountOnlyValue, 0), 0, '.', '')) ?>" min="0" step="1" aria-label="Customer refund amount"></label>
                    <label class="legacy-service-event-field legacy-service-event-field--customer-method"><span>Customer Method</span><select name="refund_payment_method" aria-label="Customer refund payment method" data-service-correction-refund-method>
                        <?php foreach ($refundPaymentMethodOptions as $refundMethodValue => $refundMethodLabel): ?>
                            <option value="<?= e($refundMethodValue) ?>" <?= (string) $refundMethodValue === 'cash' ? 'selected' : '' ?>><?= e($refundMethodLabel) ?></option>
                        <?php endforeach; ?>
                    </select></label>
                    <label class="legacy-service-event-field legacy-service-event-field--customer-account" data-service-correction-refund-treasury-row><span>Paid From</span><select name="refund_treasury_account_id" data-service-correction-refund-treasury-select aria-label="Customer refund paid from account"><option value="">Select refund account</option></select></label>
                    <label class="legacy-service-event-field legacy-service-event-field--supplier-refund"><span>Supplier Refund Received</span><input type="number" name="supplier_refund_amount" value="<?= e(number_format(round($latestSupplierRefundAmountOnlyValue, 0), 0, '.', '')) ?>" min="0" step="1" aria-label="Supplier refund received"></label>
                    <label class="legacy-service-event-field legacy-service-event-field--supplier-method"><span>Supplier Method</span><select name="supplier_refund_payment_method" aria-label="Supplier refund received method" data-service-correction-supplier-refund-method>
                        <?php foreach ($refundPaymentMethodOptions as $refundMethodValue => $refundMethodLabel): ?>
                            <option value="<?= e($refundMethodValue) ?>" <?= (string) $refundMethodValue === 'cash' ? 'selected' : '' ?>><?= e($refundMethodLabel) ?></option>
                        <?php endforeach; ?>
                    </select></label>
                    <label class="legacy-service-event-field legacy-service-event-field--supplier-account" data-service-correction-supplier-refund-treasury-row><span>Received In</span><select name="supplier_refund_treasury_account_id" data-service-correction-supplier-refund-treasury-select aria-label="Supplier refund received in account"><option value="">Select receiving account</option></select></label>
                    <label class="legacy-service-event-field" data-service-correction-refund-destination-row hidden><span>Customer Bank</span><input type="text" name="customer_bank_name" value="" maxlength="190" placeholder="Customer bank name" aria-label="Customer bank name"></label>
                    <label class="legacy-service-event-field" data-service-correction-refund-destination-row hidden><span>Account Title</span><input type="text" name="customer_bank_account_title" value="" maxlength="190" placeholder="Account title" aria-label="Customer bank account title"></label>
                    <label class="legacy-service-event-field" data-service-correction-refund-destination-row hidden><span>Account No.</span><input type="text" name="customer_bank_account_no" value="" maxlength="120" placeholder="Account no." aria-label="Customer bank account number"></label>
                    <label class="legacy-service-event-field" data-service-correction-refund-destination-row hidden><span>IBAN</span><input type="text" name="customer_bank_iban" value="" maxlength="120" placeholder="IBAN" aria-label="Customer bank IBAN"></label>
                    <label class="legacy-service-event-field" data-service-correction-refund-destination-row hidden><span>Transaction ID / Ref.</span><input type="text" name="transfer_reference" value="" maxlength="190" placeholder="Bank transaction ID, cheque no., or transfer reference" aria-label="Refund transaction ID or transfer reference"></label>
                    <label class="legacy-service-event-field" data-service-correction-refund-destination-row hidden><span>Charges</span><input type="number" name="refund_charges" value="0" min="0" step="1" aria-label="Refund transfer charges"></label>
                    <label class="legacy-service-event-field legacy-service-event-field--refund-reason legacy-service-event-field--reason-wide" data-service-refund-reason><span>Reason</span><input type="text" name="correction_reason" value="<?= e($settlementReasonValue) ?>" placeholder="Why these penalty / refund values need correction" minlength="5" maxlength="1000" required data-service-correction-settlement-reason></label>
                    <button class="btn btn-sm legacy-service-event-action legacy-service-event-action--secondary" type="submit" data-service-refund-submit>Save Correction</button>
                    <?php if ($latestRefundPrintUrl !== ''): ?>
                        <a class="btn btn-sm legacy-service-event-action legacy-service-event-action--secondary legacy-service-event-action--print-refund" href="<?= e($latestRefundPrintUrl) ?>" target="_blank" rel="noopener">Print Refund</a>
                    <?php endif; ?>
                </form>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <datalist id="service-supplier-options">
        <?php foreach ($serviceSupplierOptions as $supplierOption): ?>
            <option value="<?= e((string) $supplierOption['name']) ?>">
        <?php endforeach; ?>
    </datalist>
    <section class="customer-picker-modal" data-business-source-add-modal hidden aria-hidden="true">
        <div class="customer-picker-modal__backdrop" data-business-source-add-close></div>
        <div class="customer-picker-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="business-source-add-title" style="max-width:900px;">
            <header class="customer-picker-modal__header">
                <div>
                    <strong id="business-source-add-title">Add Account</strong>
                    <p class="muted-text">Create the business-source account that brought this booking.</p>
                </div>
                <button class="btn btn-sm" type="button" data-business-source-add-close>Close</button>
            </header>
            <form class="customer-picker-modal__body" data-business-source-add-form>
                <?= \App\Helpers\Csrf::input() ?>
                <div class="station-grid station-grid--tight">
                    <label class="station-field span-3"><span>Account Name</span><input type="text" name="name" value="" maxlength="190" required data-business-source-add-name></label>
                    <label class="station-field span-2"><span>Phone</span><input type="text" name="phone" value="" maxlength="50" data-business-source-add-phone></label>
                    <label class="station-field span-3"><span>Address</span><input type="text" name="address" value="" maxlength="500" data-business-source-add-address></label>
                    <label class="station-field span-8"><span>Description</span><input type="text" name="description" value="" maxlength="4000" data-business-source-add-description></label>
                    <input type="hidden" name="is_active" value="1">
                </div>
                <div class="workspace-feedback workspace-feedback--inline" data-business-source-add-feedback hidden></div>
                <div class="modal-actions">
                    <button class="btn btn-primary btn-sm" type="submit" data-business-source-add-submit>Save Account</button>
                    <button class="btn btn-sm" type="button" data-business-source-add-close>Cancel</button>
                </div>
            </form>
        </div>
    </section>

    <section class="customer-picker-modal" data-service-supplier-add-modal hidden aria-hidden="true">
        <div class="customer-picker-modal__backdrop" data-service-supplier-add-close></div>
        <div class="customer-picker-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="service-supplier-add-title" style="max-width:1100px;">
            <header class="customer-picker-modal__header">
                <div>
                    <strong id="service-supplier-add-title">Add Supplier</strong>
                </div>
                <button class="btn btn-sm" type="button" data-service-supplier-add-close>Close</button>
            </header>
            <form class="customer-picker-modal__body" data-service-supplier-add-form>
                <?= \App\Helpers\Csrf::input() ?>
                <div class="station-form-grid station-form-grid--12">
                    <label class="station-field span-3"><span>Supplier Name</span><input type="text" name="supplier_name" value="" maxlength="190" required data-service-supplier-add-name></label>
                    <label class="station-field span-2"><span>Branch</span><select name="branch_id" data-service-supplier-add-branch><?php foreach ($branchOptions as $branchRow): ?><option value="<?= e((string) $branchRow['id']) ?>" <?= (int) $branchRow['id'] === (int) $workspaceBooking['branchId'] ? 'selected' : '' ?>><?= e((string) $branchRow['name']) ?></option><?php endforeach; ?></select></label>
                    <label class="station-field"><span>Currency</span><select name="default_currency" data-service-supplier-add-currency><?php foreach (['PKR', 'AED', 'USD'] as $currencyOption): ?><option value="<?= e($currencyOption) ?>" <?= $currencyOption === $invoiceCurrency ? 'selected' : '' ?>><?= e($currencyOption) ?></option><?php endforeach; ?></select></label>
                    <label class="station-field span-2"><span>Supplier Type</span><select name="supplier_mode"><?php foreach ($supplierModeOptions as $modeCode => $modeLabel): ?><option value="<?= e((string) $modeCode) ?>"><?= e((string) $modeLabel) ?></option><?php endforeach; ?></select></label>
                    <label class="station-field span-4"><span>Notes</span><input type="text" name="notes" value="" maxlength="4000" placeholder="Optional"></label>
                </div>
                <div class="workspace-feedback workspace-feedback--inline" data-service-supplier-add-feedback hidden></div>
                <div class="modal-actions">
                    <button class="btn btn-primary btn-sm" type="submit" data-service-supplier-add-submit>Save Supplier</button>
                    <button class="btn btn-sm" type="button" data-service-supplier-add-close>Cancel</button>
                </div>
            </form>
        </div>
    </section>
    <datalist id="service-passenger-options">
        <?php foreach ($travelers as $travelerOption): ?>
            <?php $travelerId = (int) ($travelerOption['travelerId'] ?? 0); ?>
            <?php $travelerName = trim((string) ($travelerOption['fullName'] ?? '')); ?>
            <?php if ($travelerName !== ''): ?>
                <option value="<?= e($travelerName) ?>" data-traveler-id="<?= e((string) $travelerId) ?>">
            <?php endif; ?>
        <?php endforeach; ?>
    </datalist>

    <div class="legacy-party-grid" data-workflow-gate="customer-stage">
        <section class="legacy-box legacy-passenger-box">
            <h2>Passengers / Family Members</h2>
            <table class="legacy-table legacy-service-table">
                <thead><tr><th>Sr.</th><th>Passenger Name</th><th>Relation</th><th>PNR</th><th>Route</th><th>Invoice Amount</th><th>Paid</th><th>Outstanding</th><th>Remarks</th></tr></thead>
                <tbody>
                <?php foreach ($passengerSummaryRows as $index => $passengerSummaryRow): ?>
                    <tr data-service-row="true" data-service-index="<?= e((string) $index) ?>" class="<?= $index === 0 ? 'is-active' : '' ?>">
                        <td><?= e((string) ($index + 1)) ?></td>
                        <td><?= e((string) ($passengerSummaryRow['passengerName'] ?? 'Passenger')) ?></td>
                        <td><?= e((string) ($passengerSummaryRow['relation'] ?? 'Passenger')) ?></td>
                        <td><?= e((string) ($passengerSummaryRow['pnr'] ?? 'N/A')) ?></td>
                        <td><?= e((string) ($passengerSummaryRow['route'] ?? 'N/A')) ?></td>
                        <td data-passenger-summary-invoice><?= e((string) ($passengerSummaryRow['invoiceAmountDisplay'] ?? 'PKR 0')) ?></td>
                        <td data-passenger-summary-paid><?= e((string) ($passengerSummaryRow['paidDisplay'] ?? 'PKR 0')) ?></td>
                        <td data-passenger-summary-outstanding><?= e((string) ($passengerSummaryRow['outstandingDisplay'] ?? 'PKR 0')) ?></td>
                        <td><?= e((string) ($passengerSummaryRow['remarks'] ?? '')) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </section>
    </div>

    <div class="legacy-financial-grid" data-commercial-editor="active">
        <section class="legacy-fin-block legacy-fin-block--segregation">
            <h3>A) Main Segregation / Sub Segregation</h3>
            <div class="legacy-fin-fields">
                <label><span>Main Segregation</span><input type="text" value="001" readonly></label>
                <label><span>IATA Fare</span><input type="number" form="legacy-service-form" name="ticket_fare" step="0.01" value="<?= e((string) ($activeService['fare'] ?? 0)) ?>" data-ticket-metric="fare"></label>
                <label><span>Other Fare</span><input id="commercial-other-fare" type="number" form="legacy-service-form" name="other_fare" step="0.01" value="<?= e((string) ($activeService['otherFare'] ?? 0)) ?>" data-service-metric="other_fare"></label>
                <label><span>SPYI</span><input id="commercial-spyi-amount" type="number" form="legacy-service-form" name="spyi_amount" step="0.01" value="<?= e((string) ($activeService['spyiAmount'] ?? 0)) ?>" data-service-metric="spyi_amount"></label>
                <label><span>YQ</span><input id="commercial-yq-amount" type="number" form="legacy-service-form" name="yq_amount" step="0.01" value="<?= e((string) ($activeService['yqAmount'] ?? 0)) ?>" data-service-metric="yq_amount"></label>
                <label><span>VAT Input</span><input id="commercial-vat-input" type="number" form="legacy-service-form" name="vat_input" step="0.01" value="<?= e((string) ($activeService['vatInput'] ?? 0)) ?>" data-service-metric="vat_input"></label>
                <label><span>Sub Segregation</span><input type="text" value="001" readonly></label>
                <label><span>Mkt.Fare</span><input id="commercial-sale-price" type="number" form="legacy-service-form" name="sale_price" step="0.01" value="<?= e((string) ($activeService['salePrice'] ?? 0)) ?>" data-service-metric="sale"></label>
                <label><span>Cost Curr.</span><select id="commercial-cost-currency" name="cost_currency" form="legacy-service-form" data-service-field="cost_currency"><?php foreach (['PKR', 'AED', 'USD'] as $currencyOption): ?><option value="<?= e($currencyOption) ?>" <?= $currencyOption === (string) ($activeService['costCurrency'] ?? $activeService['currency'] ?? $invoiceCurrency) ? 'selected' : '' ?>><?= e($currencyOption) ?></option><?php endforeach; ?></select></label>
                <label><span>Soto Fare</span><input id="commercial-soto-fare" type="number" form="legacy-service-form" name="soto_fare" step="0.01" value="<?= e((string) ($activeService['sotoFare'] ?? 0)) ?>" data-service-metric="soto_fare"></label>
                <label><span>AQ/YR/PK</span><input id="commercial-aqyrpk-amount" type="number" form="legacy-service-form" name="aq_yr_pk_amount" step="0.01" value="<?= e((string) ($activeService['aqYrPkAmount'] ?? 0)) ?>" data-service-metric="aq_yr_pk_amount"></label>
                <label><span>OTH.</span><input id="commercial-oth-amount" type="number" form="legacy-service-form" name="oth_amount" step="0.01" value="<?= e((string) ($activeService['othAmount'] ?? 0)) ?>" data-service-metric="oth_amount"></label>
                <label><span>Taxes</span><input id="commercial-taxes" type="number" form="legacy-service-form" name="taxes" step="0.01" value="<?= e((string) ($activeService['taxes'] ?? 0)) ?>" data-service-metric="tax"></label>
                <label><span>Fr+Tx.</span><input id="commercial-airline-payable" type="text" value="<?= e($activeServiceFrTxValue) ?>" readonly data-airline-payable-field></label>
                <input id="commercial-purchase-cost" type="hidden" form="legacy-service-form" name="purchase_cost" value="<?= e((string) ($activeService['purchaseCost'] ?? 0)) ?>" data-service-metric="cost">
                <input id="commercial-pricing-exchange-rate" type="hidden" form="legacy-service-form" name="pricing_exchange_rate" value="<?= e((string) ($activeService['pricingExchangeRate'] ?? 1)) ?>" data-service-field="pricing_exchange_rate">
                <input id="commercial-pricing-rate-effective-date" type="hidden" form="legacy-service-form" name="pricing_rate_effective_date" value="<?= e((string) ($activeService['pricingRateEffectiveDate'] ?? date('Y-m-d'))) ?>" data-service-field="pricing_rate_effective_date">
            </div>
        </section>
        <div class="legacy-fin-stack legacy-fin-stack--airline-client">
            <section class="legacy-fin-block legacy-fin-block--airline">
                <h3>B) ---AIRLINE---</h3>
                <div class="legacy-airline-fields">
                    <label><span>Comm.%</span><input type="number" value="0.00" readonly></label>
                    <label><span>Com.Amt</span><input type="number" form="legacy-service-form" name="commission" step="0.01" value="<?= e((string) ($activeService['commission'] ?? 0)) ?>" data-service-metric="commission"></label>
                    <label><span>Extra %</span><input type="number" value="0.00" readonly></label>
                    <label><span>Ext.Com.</span><input type="number" step="0.01" value="0.00" data-airline-commission-extra></label>
                    <label><span>Com.Adj.</span><input type="number" step="0.01" value="0.00" data-airline-commission-adjustment></label>
                    <label class="legacy-airline-total"><span>Tot.Com.</span><input type="text" value="<?= e($formatMoney((float) ($activeService['commission'] ?? 0))) ?>" readonly data-airline-commission-total></label>
                </div>
            </section>
            <section class="legacy-fin-block legacy-fin-block--client legacy-fin-block--client-repaired">
                <h3>C) CLIENT SERVICE CHARGES & CLIENT DISCOUNT</h3>
                <div class="legacy-client-fields legacy-client-fields--stacked">
                    <div class="legacy-client-row">
                        <div class="legacy-client-row__label">Service Chgs %</div>
                        <div class="legacy-client-row__control"><input id="commercial-service-charge-percent" type="number" step="0.01" value="0.00" data-service-percent="service_charge"></div>
                    </div>
                    <div class="legacy-client-row">
                        <div class="legacy-client-row__label">Serv.Amount</div>
                        <div class="legacy-client-row__control"><input id="commercial-service-charge" type="number" form="legacy-service-form" name="service_charge" step="0.01" value="<?= e((string) ($activeService['serviceCharge'] ?? 0)) ?>" data-service-metric="service_charge"></div>
                    </div>
                    <div class="legacy-client-row">
                        <div class="legacy-client-row__label">Disc.%</div>
                        <div class="legacy-client-row__control"><input id="commercial-discount-percent" type="number" step="0.01" value="0.00" data-service-percent="discount"></div>
                    </div>
                    <div class="legacy-client-row">
                        <div class="legacy-client-row__label">Disc.Amt.</div>
                        <div class="legacy-client-row__control"><input id="commercial-discount-amount" type="number" form="legacy-service-form" name="discount_amount" step="0.01" value="<?= e((string) ($activeService['discountAmount'] ?? 0)) ?>" data-service-discount></div>
                    </div>
                    <div class="legacy-client-row">
                        <div class="legacy-client-row__label legacy-vat-label">Vat %</div>
                        <div class="legacy-client-row__control"><input id="commercial-vat-percent" type="number" step="0.01" value="0.00" data-service-percent="vat"></div>
                    </div>
                    <div class="legacy-client-row">
                        <div class="legacy-client-row__label">Vat Output</div>
                        <div class="legacy-client-row__control"><input id="commercial-vat-output" type="number" form="legacy-service-form" name="vat" step="0.01" value="<?= e((string) ($activeService['vat'] ?? 0)) ?>" data-service-metric="vat"></div>
                    </div>
                    <div class="legacy-client-row legacy-client-row--sale-adjustment">
                        <div class="legacy-client-row__label">Sale Adj.</div>
                        <div class="legacy-client-row__control"><input id="commercial-sale-adjustment" type="number" step="1" value="<?= e(number_format($activeServiceManualSaleAdjustment, 0, '.', '')) ?>" data-service-sale-adjustment readonly></div>
                    </div>
                    <div class="legacy-client-row">
                        <div class="legacy-client-row__label">Final Sale Amount</div>
                        <div class="legacy-client-row__control"><input id="commercial-final-sale-price" type="number" form="legacy-service-form" name="final_sale_price" step="1" value="<?= e(number_format(round($activeServiceFinalSalePrice, 0), 0, '.', '')) ?>" data-service-final-sale data-manual-override="<?= $activeServiceHasManualFinalSaleOverride ? '1' : '0' ?>"></div>
                    </div>
                    <div class="legacy-client-row" data-loss-amount-panel <?= $activeServiceHasLoss ? '' : 'hidden' ?>>
                        <div class="legacy-client-row__label">Loss</div>
                        <div class="legacy-client-row__control">
                            <input id="commercial-loss-amount" type="number" step="1" value="<?= e(number_format(round($activeServiceLossDelta, 0), 0, '.', '')) ?>" data-service-loss-amount readonly>
                        </div>
                    </div>

                    <div class="legacy-client-row legacy-client-row--reason" data-loss-reason-panel <?= $activeServiceHasLoss ? '' : 'hidden' ?>>
                        <div class="legacy-client-row__label">Loss Reason</div>
                        <div class="legacy-client-row__control">
                            <textarea form="legacy-service-form" name="loss_reason" maxlength="4000" data-service-field="lossReason" placeholder="Required when final sale is below Fr+Tx. Explain why this service is being sold below Fr+Tx." <?= $activeServiceHasLoss ? 'required' : 'disabled' ?>><?= e((string) ($activeService['lossReason'] ?? '')) ?></textarea>
                        </div>
                    </div>
                </div>
                <?php if ($showWorkspaceDebug): ?>
                <div class="legacy-client-debug" data-commercial-debug style="padding:6px 10px;font-size:11px;color:#5a5a5a;white-space:pre-wrap;">
                    Commercial live: waiting for editor binding...
                </div>
                <?php endif; ?>
            </section>
        </div>
        <section class="legacy-fin-block">
            <h3>D) Financial Summary</h3>
            <div class="legacy-fin-summary-fields">
                <label><span>Tot.Sp</span><input id="commercial-total-sp" type="text" value="<?= e($serviceSaleTotal) ?>" readonly data-total-sp-field></label>
                <label><span>Disc.Adj.%</span><input type="text" value="0.00" readonly></label>
                <label><span>K.B+Comm</span><input type="text" value="0.00" readonly></label>
                <label class="legacy-fin-summary-inline"><span>Comm. After K.B.</span><select disabled><option selected>N</option><option>Y</option></select></label>
                <label><span>K.B. Airline</span><input type="text" value="0.00" readonly></label>
                <label><span>K.B. Cust.</span><input type="text" value="0.00" readonly></label>
                <label class="legacy-fin-summary-ticket"><span>Tkt.Value</span><input id="commercial-ticket-value" class="legacy-yellow" type="text" value="<?= e($formatMoney((float) ($activeService['purchaseCost'] ?? 0))) ?>" readonly data-ticket-value-field></label>
                <label class="legacy-fin-summary-fc"><span>fC</span><select disabled><option selected><?= e((string) ($activeService['currency'] ?? 'PKR')) ?></option></select></label>
                <label><span>F/c Receivable</span><input id="commercial-client-receivable" type="text" value="<?= e($clientReceivable) ?>" readonly data-client-receivable-field></label>
                <label><span>F/c Payable</span><input id="commercial-airline-payable-financial" type="text" value="<?= e($airlinePayable) ?>" readonly data-airline-payable-financial-field></label>
            </div>
        </section>

        <form class="legacy-payment-strip legacy-payment-strip--rail" method="post" action="<?= e(url('/workspace/payments/receipts/save')) ?>">
            <?= \App\Helpers\Csrf::input() ?>
            <input type="hidden" name="booking_id" value="<?= e((string) $workspaceBooking['id']) ?>">
            <input type="hidden" name="branch_id" value="<?= e((string) $workspaceBooking['branchId']) ?>">
            <input type="hidden" value="<?= e($invoiceCurrency . ' ' . number_format(round($sameCurrencyPreviousBalanceAmount, 0), 0)) ?>" data-payment-previous-balance="<?= e((string) $sameCurrencyPreviousBalanceAmount) ?>" data-payment-previous-balance-map="<?= e(json_encode($previousBalanceTotals, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?: '{}') ?>" data-payment-open-balance-map="<?= e(json_encode($customerOpenBalanceTotals, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?: '{}') ?>">
            <?php if ($receiptRecreateDraft !== null): ?>
                <div class="workspace-feedback workspace-feedback--inline" style="display:block;margin-bottom:12px;">
                    Draft loaded from voided receipt <strong><?= e((string) ($receiptRecreateDraft['receiptNo'] ?? '')) ?></strong>.
                    Review the details below and save to create a brand new receipt.
                    <a href="<?= e($receiptRecreateClearUrl) ?>" style="margin-left:8px;">Clear Draft</a>
                </div>
            <?php endif; ?>
            <div class="legacy-payment-caption">
                <strong>E) Payment Summary / Receive</strong>
            </div>
            <?php if ($activeServiceIsCancelled): ?>
                <div class="legacy-cancelled-service-banner" role="status">
                    <strong>Cancelled service</strong>
                    <span><?= e($activeServiceCancellationNotice) ?></span>
                </div>
            <?php endif; ?>
            <div class="legacy-payment-panels">
                <section class="legacy-payment-panel legacy-payment-panel--invoice">
                    <div class="legacy-payment-panel__title">CURRENT INVOICE</div>
                    <div class="legacy-payment-panel__body legacy-payment-panel__body--form">
                        <label><span>Invoice Currency</span><select id="commercial-invoice-currency" name="currency" form="legacy-service-form" data-service-field="currency"><?php foreach (['PKR', 'AED', 'USD'] as $currencyOption): ?><option value="<?= e($currencyOption) ?>" <?= $currencyOption === (string) ($activeService['currency'] ?? $invoiceCurrency) ? 'selected' : '' ?>><?= e($currencyOption) ?></option><?php endforeach; ?></select></label>
                        <label data-payment-current-invoice-row<?= $hasCurrentInvoiceAmount ? '' : ' hidden' ?>><span>Invoice Amount</span><input id="commercial-payment-current-invoice" type="number" step="1" value="<?= e(number_format(round($currentInvoiceAmountValue, 0), 0, '.', '')) ?>" data-payment-current-invoice="<?= e((string) $currentInvoiceAmountValue) ?>" data-payment-currency="<?= e($invoiceCurrency) ?>"></label>
                        <label data-payment-paid-current-invoice-row<?= $currentReceivedPersistedAmount > 0.005 ? '' : ' hidden' ?>><span>Paid on This Invoice</span><input id="commercial-payment-already-received" type="text" value="<?= e($paidOnCurrentInvoiceDisplay) ?>" data-payment-already-received data-payment-persisted-received="<?= e((string) $currentReceivedPersistedAmount) ?>" readonly></label>
                        <label data-payment-current-balance-row<?= $hasCurrentInvoiceAmount ? '' : ' hidden' ?>><span>Invoice Balance</span><input id="commercial-payment-current-balance" class="legacy-red-text" type="text" value="<?= e($currentInvoiceBalance) ?>" data-payment-current-balance data-payment-persisted-invoice-balance="<?= e((string) $effectiveCurrentInvoiceDueValue) ?>" data-payment-saved-invoice-balance="<?= e((string) $effectiveCurrentInvoiceDueValue) ?>" data-payment-balance-locked="<?= $lockCurrentInvoiceBalance ? '1' : '0' ?>" readonly></label>
                        <label class="legacy-payment-credit-row" data-payment-customer-credit-row<?= $sameCurrencyCustomerCreditAmount > 0.005 ? '' : ' hidden' ?>><span>Available Customer Credit</span><input id="commercial-payment-customer-credit" class="legacy-payment-credit-value" type="text" value="<?= e($sameCurrencyCustomerCreditDisplay) ?>" data-payment-customer-credit="<?= e((string) $sameCurrencyCustomerCreditAmount) ?>" data-payment-customer-credit-map="<?= e($customerCreditTotalsJson) ?>" readonly></label>
                    </div>
                </section>
                <section class="legacy-payment-panel legacy-payment-panel--open-balance">
                    <div class="legacy-payment-panel__title">CUSTOMER OUTSTANDING BALANCE</div>
                    <div class="legacy-payment-panel__body legacy-payment-panel__body--balances">
                        <div data-payment-previous-balances-block<?= $visibleCustomerOpenBalanceTotals !== [] ? '' : ' hidden' ?>>
                            <div data-payment-previous-balance-list>
                                <?php foreach ($visibleCustomerOpenBalanceTotals as $currencyCode => $amount): ?>
                                    <label><span><?= e((string) $currencyCode) ?></span><input class="legacy-red-text" type="text" value="<?= e((string) $currencyCode . ' ' . number_format(round((float) $amount, 0), 0)) ?>" readonly></label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="legacy-payment-passenger-list" data-payment-passenger-balance-list>
                            <?php foreach ($passengerSummaryRows as $passengerSummaryIndex => $passengerSummaryRow): ?>
                                <div class="legacy-payment-passenger-item<?= $passengerSummaryIndex === 0 ? ' is-lead' : '' ?>">
                                    <div class="legacy-payment-passenger-item__top">
                                        <strong><?= e((string) ($passengerSummaryRow['passengerName'] ?? 'Passenger')) ?></strong>
                                        <span><?= e((string) ($passengerSummaryRow['relation'] ?? 'Passenger')) ?></span>
                                    </div>
                                    <div class="legacy-payment-passenger-item__meta">
                                        <span>Paid: <?= e((string) ($passengerSummaryRow['paidDisplay'] ?? 'PKR 0')) ?></span>
                                        <span>Outstanding: <?= e((string) ($passengerSummaryRow['outstandingDisplay'] ?? 'PKR 0')) ?></span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="workspace-feedback workspace-feedback--inline" data-payment-no-previous-balance hidden style="display:none !important;" aria-hidden="true"></div>
                    </div>
                </section>
                <section class="legacy-payment-panel legacy-payment-panel--receive">
                    <div class="legacy-payment-panel__title">RECEIVE PAYMENT</div>
                    <div class="legacy-payment-panel__body legacy-payment-panel__body--form">
                        <label class="legacy-payment-panel__full-row" data-payment-receipt-scope-row><span>Collection Mode</span><select name="receipt_scope" data-payment-receipt-scope><option value="whole_invoice" selected>Whole Invoice / Auto Split</option><option value="passenger_specific">Selected Passenger Due Only</option></select></label>
                        <label class="legacy-payment-panel__full-row" data-payment-target-row hidden><span>Choose Passenger Due</span><select name="target_receivable_item_id" data-payment-target-receivable><option value="">Select passenger due</option></select></label>
                        <label><span>Payment Currency</span><select name="receipt_currency" data-payment-currency-select><?php foreach (['PKR', 'AED', 'USD'] as $currencyOption): ?><option value="<?= e($currencyOption) ?>" <?= $currencyOption === $paymentCurrency ? 'selected' : '' ?>><?= e($currencyOption) ?></option><?php endforeach; ?></select></label>
                        <input id="commercial-payment-total-outstanding" type="hidden" value="<?= e($paymentBalance) ?>" data-payment-total-outstanding="<?= e((string) $paymentBalanceAmount) ?>" data-payment-total-due-now="<?= e((string) $effectiveCurrentInvoiceDueValue) ?>" readonly>
                        <label<?= $showCurrentInvoiceBalancePkrEquivalent ? '' : ' hidden' ?> data-payment-current-balance-pkr-row><span>PKR Equivalent of Current Balance</span><input id="commercial-payment-current-balance-pkr" type="text" value="<?= e($currentInvoiceBalancePkrEquivalent) ?>" data-payment-current-balance-pkr data-payment-pkr-rate="<?= e((string) ($currentInvoiceBalancePkrRate ?? 0)) ?>" readonly></label>
                        <label>
                            <span>Amount Receiving</span>
                            <input
                                type="number"
                                name="received_amount"
                                step="1"
                                value="<?= e($amountReceivedNow) ?>"
                                data-payment-focus="received_amount"
                                data-payment-partial-date-trigger
                            >
                        </label>
                        <label class="legacy-payment-panel__full-row" data-payment-advance-row>
                            <span>Customer Advance</span>
                            <select name="advance_receipt_id" data-payment-advance-select>
                                <option value="">No advance available</option>
                            </select>
                        </label>
                        <label class="legacy-payment-panel__full-row" data-payment-advance-amount-row hidden>
                            <span>Advance Used</span>
                            <input type="number" name="advance_apply_amount" step="1" value="0" data-payment-advance-amount>
                        </label>
                        <label data-payment-due-row>
                            <span>Due Date</span>
                            <input
                                type="date"
                                name="due_date"
                                value="<?= e($invoiceDueDate) ?>"
                                autocomplete="off"
                                data-payment-due-date
                                data-payment-due-picker
                            >
                        </label>
                        <label data-payment-return-row hidden><span>Return Amount</span><input id="commercial-payment-return-amount" class="legacy-red-text" type="text" value="PKR 0" readonly data-payment-return-amount></label>
                        <label><span>Payment Method</span><select name="payment_method"><?php foreach ($paymentMethodOptions as $paymentMethodValue => $paymentMethodLabel): ?><option value="<?= e((string) $paymentMethodValue) ?>" <?= $receiptDraftMethod === (string) $paymentMethodValue ? 'selected' : '' ?>><?= e((string) $paymentMethodLabel) ?></option><?php endforeach; ?></select></label>
                        <label data-direct-supplier-payment-row hidden>
                            <span>Supplier Payable</span>
                            <input type="hidden" name="direct_supplier_service_line_reference" value="" data-direct-supplier-service-line-reference>
                            <select name="direct_supplier_obligation_id" data-direct-supplier-obligation-select>
                                <option value="">Select supplier payable</option>
                                <?php foreach (($supplierFoundation['openObligations'] ?? []) as $directSupplierObligation): ?>
                                    <?php
                                        $directSupplierBalance = round((float) ($directSupplierObligation['balanceDueAmount'] ?? $directSupplierObligation['netPayableAmount'] ?? 0), 2);
                                        $directSupplierCurrency = (string) ($directSupplierObligation['currency'] ?? '');
                                        $directSupplierLabel = trim((string) ($directSupplierObligation['supplier'] ?? $directSupplierObligation['supplier_name'] ?? 'Supplier'));
                                        $directSupplierService = trim((string) ($directSupplierObligation['serviceLineReference'] ?? $directSupplierObligation['service_line_reference'] ?? ''));
                                        $directSupplierOptionText = trim($directSupplierLabel . ($directSupplierService !== '' ? ' / ' . $directSupplierService : '') . ' / ' . $directSupplierCurrency . ' ' . number_format($directSupplierBalance, 0));
                                    ?>
                                    <option value="<?= e((string) ($directSupplierObligation['id'] ?? 0)) ?>" data-currency="<?= e($directSupplierCurrency) ?>" data-balance="<?= e((string) $directSupplierBalance) ?>"><?= e($directSupplierOptionText) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label data-payment-treasury-row hidden>
                            <span>Cash / Bank Account</span>
                            <select name="treasury_account_id" data-payment-treasury-select data-initial-value="<?= e((string) $receiptDraftTreasuryAccountId) ?>">
                                <option value="">Select account</option>
                            </select>
                        </label>
                    </div>
                </section>
                <section class="legacy-payment-panel legacy-payment-panel--actions">
                    <div class="legacy-payment-panel__title">ACTIONS</div>
                    <div class="legacy-payment-panel__body legacy-payment-panel__body--actions">
                        <div class="legacy-payment-actions">
                            <div class="legacy-payment-actions__row legacy-payment-actions__row--primary">
                                <button class="btn btn-primary btn-sm legacy-payment-primary" type="button" name="receipt_action" value="save" data-payment-submit-action="save" data-payment-action="save-payment">Save Payment</button>
                                <button class="btn btn-success btn-sm legacy-payment-receipt" type="button" data-payment-action="print-receipt" data-payment-print-url="<?= e($latestReceiptUrl) ?>" data-payment-latest-receipt-id="<?= e((string) $latestReceiptId) ?>">Print Receipt</button>
                            </div>
                            <div class="legacy-payment-actions__row">
                                <button class="btn btn-sm" type="button" data-workspace-action="payment-history" data-workflow-control="payment-history" data-payment-action="payment-history">Payment History</button>
                                <a class="btn btn-sm" href="<?= e($ledgerUrl) ?>" <?= $workspaceBooking['id'] > 0 ? 'target="_blank" rel="noopener"' : '' ?> data-payment-action="customer-ledger" data-customer-ledger-link>View Customer Ledger</a>
                            </div>
                            <div class="legacy-payment-actions__row">
                                <button class="btn btn-sm" type="button" data-workspace-action="add-service" data-workflow-control="add-service" <?= $hasActiveServices ? '' : 'disabled' ?>>Add Service</button>
                                <button class="btn btn-sm" type="button" data-payment-exchange-settlement data-payment-action="exchange-settlement">Exchange Settlement</button>
                            </div>
                        </div>
                    </div>
                </section>
            </div>
            <input type="hidden" value="0" data-quick-receive-input>
            <input type="hidden" name="receipt_date" value="<?= e($receiptDraftDate) ?>">
            <input type="hidden" name="reference_number" value="<?= e($receiptDraftReferenceNumber) ?>">
            <input type="hidden" name="bank_card_detail" value="<?= e($receiptDraftBankCardDetail) ?>">
            <input type="hidden" name="charges_amount" value="0">
            <input type="hidden" name="receipt_status" value="received">
            <input type="hidden" name="exchange_rate_to_booking" value="">
            <input type="hidden" name="receipt_remarks" value="<?= e($receiptDraftRemarks) ?>">
            <input type="hidden" name="settlement_mode" value="normal" data-payment-settlement-mode>
            <input type="hidden" name="settlement_target_receivable_id" value="" data-payment-settlement-target-id>
            <input type="hidden" name="settlement_target_currency" value="" data-payment-settlement-target-currency>
            <input type="hidden" name="settlement_target_receivable_amount" value="" data-payment-settlement-target-receivable-amount>
            <input type="hidden" name="settlement_target_payment_amount" value="" data-payment-settlement-target-payment-amount>
            <input type="hidden" name="settlement_rate_from_currency" value="" data-payment-settlement-rate-from>
            <input type="hidden" name="settlement_rate_to_currency" value="" data-payment-settlement-rate-to>
            <input type="hidden" name="settlement_exchange_rate" value="" data-payment-settlement-rate>
            <input type="hidden" name="settlement_exchange_rate_effective_date" value="<?= e(date('Y-m-d')) ?>" data-payment-settlement-rate-date>
    <section class="customer-picker-modal customer-picker-modal--child" data-payment-detail-modal hidden aria-hidden="true">
        <div class="customer-picker-modal__backdrop" data-payment-detail-close></div>
        <div class="customer-picker-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="payment-detail-title" style="max-width:620px;">
            <header class="customer-picker-modal__header">
                <h3 id="payment-detail-title">Payment Details</h3>
                <button type="button" class="customer-picker-modal__close" data-payment-detail-close>&times;</button>
            </header>
            <div class="customer-picker-modal__body">
                <p class="muted-text" data-payment-detail-method-label>Record reference details for this non-cash payment.</p>
                <div class="legacy-modal-grid">
                    <label class="span-2">
                        <span>Reference / Transaction No.</span>
                        <input type="text" maxlength="100" data-payment-detail-reference placeholder="Transaction ID, slip no., approval code">
                    </label>
                    <label class="span-2">
                        <span>Bank / Channel / Card Detail</span>
                        <input type="text" maxlength="190" data-payment-detail-bank-card placeholder="Bank name, channel, POS, last 4 digits">
                    </label>
                    <label>
                        <span>Charges</span>
                        <input type="number" step="1" min="0" value="0" data-payment-detail-charges>
                    </label>
                    <label class="span-2">
                        <span>Remarks</span>
                        <input type="text" maxlength="4000" data-payment-detail-remarks placeholder="Optional note">
                    </label>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-primary btn-sm" data-payment-detail-apply>Save</button>
                    <button type="button" class="btn btn-sm" data-payment-detail-close>Cancel</button>
                </div>
            </div>
        </div>
    </section>
        </form>
    </div>

    <div class="legacy-highlight-strip">
        <div class="legacy-highlight legacy-highlight--red"><span>Airline Payable</span><strong id="commercial-airline-payable-summary" data-airline-payable-summary><?= e($airlinePayable) ?></strong></div>
        <div class="legacy-highlight legacy-highlight--red"><span>Receivable (Client)</span><strong id="commercial-client-receivable-summary" data-client-receivable-summary><?= e($clientReceivable) ?></strong></div>
        <div class="legacy-highlight legacy-highlight--orange"><span>Other Payable</span><strong id="commercial-other-payable-summary" data-other-payable-summary><?= e($otherPayable) ?></strong></div>
        <div class="legacy-highlight legacy-highlight--pink"><span>Profit/Loss</span><strong id="commercial-service-profit" data-service-profit><?= e($profitLoss) ?></strong></div>
    </div>

    <div class="legacy-totals-strip">
        <label><span>K.B</span><input type="text" value="0.00" readonly></label>
        <label><span>Comm.</span><input type="text" value="<?= e($formatCurrencyTotals($sumByCurrency($activePersistedServiceLines, 'currency', static fn (array $row): float => (float) ($row['commission'] ?? 0)))) ?>" readonly></label>
        <label><span>Profit/Loss</span><input id="commercial-bottom-profit" type="text" value="<?= e($profitLoss) ?>" readonly></label>
        <label><span>Total Fare</span><input id="commercial-bottom-fare" type="text" value="<?= e($basicFareTotal) ?>" readonly></label>
        <label><span>Total Taxes</span><input id="commercial-bottom-taxes" type="text" value="<?= e($taxTotal) ?>" readonly></label>
        <label><span>Total Other</span><input id="commercial-bottom-other" type="text" value="<?= e($otherTotal) ?>" readonly></label>
        <label><span>Total S.P</span><input id="commercial-bottom-sp" type="text" value="<?= e($serviceSaleTotal) ?>" readonly></label>
        <label><span>Total Rcvable</span><input id="commercial-bottom-receivable" class="legacy-red-value" type="text" value="<?= e($clientReceivable) ?>" readonly></label>
        <label><span>Total Payable</span><input id="commercial-bottom-payable" type="text" value="<?= e($airlinePayable) ?>" readonly></label>
    </div>

    <div id="dock-panel-reminders" class="legacy-reminders-shell">
        <section class="legacy-payment-panel legacy-reminders-panel">
            <div class="legacy-payment-panel__title">REMINDERS</div>
            <div class="legacy-payment-panel__body legacy-reminders-panel__body">
                <div class="legacy-reminder-counts" data-reminder-summary>
                    <div class="legacy-reminder-chip">
                        <span>Total</span>
                        <strong><?= e((string) ($reminderCounts['open'] + $reminderCounts['due'] + $reminderCounts['completed'] + $reminderCounts['dismissed'])) ?></strong>
                    </div>
                    <div class="legacy-reminder-chip legacy-reminder-chip--due">
                        <span>Due</span>
                        <strong><?= e((string) ($reminderCounts['due'] ?? 0)) ?></strong>
                    </div>
                    <div class="legacy-reminder-chip legacy-reminder-chip--overdue">
                        <span>Overdue</span>
                        <strong><?= e((string) ($reminderCounts['overdue'] ?? 0)) ?></strong>
                    </div>
                    <div class="legacy-reminder-chip">
                        <span>Upcoming</span>
                        <strong><?= e((string) ($reminderCounts['upcoming'] ?? 0)) ?></strong>
                    </div>
                    <div class="legacy-reminder-chip">
                        <span>Receivable Alerts</span>
                        <strong data-receivable-alert-count-total><?= e((string) ($activeReceivableAlertCounts['total'] ?? 0)) ?></strong>
                    </div>
                </div>

                <form
                    method="post"
                    action="<?= e(url('/workspace/reminders/save')) ?>"
                    class="legacy-reminder-form<?= $remindersReady ? '' : ' legacy-reminder-form--disabled' ?>"
                    data-reminder-form
                    aria-disabled="<?= $remindersReady ? 'false' : 'true' ?>"
                >
                    <?= \App\Helpers\Csrf::input() ?>
                    <input type="hidden" name="booking_id" value="<?= e((string) ($workspaceBooking['id'] ?? 0)) ?>">
                    <input type="hidden" name="reminder_id" value="<?= e((string) ($editingReminderForm['id'] ?? 0)) ?>">

                    <label>
                        <span>Reminder Type</span>
                        <select name="reminder_type" <?= $remindersReady ? '' : 'disabled' ?>>
                            <?php foreach ($reminderTypeOptions as $typeCode => $typeLabel): ?>
                                <option value="<?= e((string) $typeCode) ?>" <?= (string) ($editingReminderForm['type'] ?? 'custom_manual') === (string) $typeCode ? 'selected' : '' ?>><?= e((string) $typeLabel) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label>
                        <span>Linked To</span>
                        <select name="linked_target" <?= $remindersReady ? '' : 'disabled' ?>>
                            <?php if ($reminderLinkTargets === []): ?>
                                <option value="<?= e('booking:' . (int) ($workspaceBooking['id'] ?? 0)) ?>">Booking File</option>
                            <?php else: ?>
                                <?php foreach ($reminderLinkTargets as $target): ?>
                                    <option value="<?= e((string) ($target['value'] ?? '')) ?>" <?= (string) ($editingReminderForm['linkedTarget'] ?? '') === (string) ($target['value'] ?? '') ? 'selected' : '' ?>><?= e((string) ($target['label'] ?? 'Booking File')) ?></option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </label>

                    <label>
                        <span>Due</span>
                        <input type="datetime-local" name="due_at" value="<?= e((string) ($editingReminderForm['dueAt'] ?? '')) ?>" <?= $remindersReady ? '' : 'disabled' ?>>
                    </label>

                    <label>
                        <span>Channel</span>
                        <select name="channel" <?= $remindersReady ? '' : 'disabled' ?>>
                            <?php foreach ($reminderChannels as $channel): ?>
                                <option value="<?= e((string) $channel) ?>" <?= (string) ($editingReminderForm['channel'] ?? 'Call') === (string) $channel ? 'selected' : '' ?>><?= e((string) $channel) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label class="legacy-reminder-form__wide">
                        <span>Task</span>
                        <input type="text" name="title" maxlength="190" value="<?= e((string) ($editingReminderForm['title'] ?? '')) ?>" placeholder="Passport follow-up / supplier call / payment reminder" data-reminder-focus="task" <?= $remindersReady ? '' : 'disabled' ?>>
                    </label>

                    <label>
                        <span>Owner</span>
                        <input type="text" name="owner_label" maxlength="120" value="<?= e((string) ($editingReminderForm['owner'] ?? '')) ?>" placeholder="Operations Desk" <?= $remindersReady ? '' : 'disabled' ?>>
                    </label>

                    <label>
                        <span>Priority</span>
                        <select name="priority" <?= $remindersReady ? '' : 'disabled' ?>>
                            <option value="normal" <?= (string) ($editingReminderForm['priority'] ?? 'normal') === 'normal' ? 'selected' : '' ?>>Normal</option>
                            <option value="high" <?= (string) ($editingReminderForm['priority'] ?? 'normal') === 'high' ? 'selected' : '' ?>>High</option>
                        </select>
                    </label>

                    <label class="legacy-reminder-form__wide legacy-reminder-form__notes">
                        <span>Notes</span>
                        <input type="text" name="reminder_note" maxlength="4000" value="<?= e((string) ($editingReminderForm['note'] ?? '')) ?>" placeholder="Optional follow-up note" <?= $remindersReady ? '' : 'disabled' ?>>
                    </label>

                    <div class="legacy-reminder-form__actions">
                        <button class="btn btn-primary btn-sm" type="submit" <?= $remindersReady ? '' : 'disabled' ?>><?= (int) ($editingReminderForm['id'] ?? 0) > 0 ? 'Update Reminder' : 'Save Reminder' ?></button>
                        <?php if ((int) ($editingReminderForm['id'] ?? 0) > 0): ?>
                            <a class="btn btn-sm" href="<?= e($reminderEditResetUrl) ?>">Cancel Edit</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </section>

        <section class="legacy-payment-panel legacy-reminders-panel legacy-reminders-panel--register">
            <div class="legacy-payment-panel__title">OPEN REMINDERS</div>
            <div class="legacy-payment-panel__body legacy-reminders-panel__body">
                <table class="legacy-table legacy-reminders-table" data-reminder-table>
                    <thead>
                        <tr>
                            <th>Type</th>
                            <th>Task</th>
                            <th>Linked To</th>
                            <th>Due</th>
                            <th>Channel</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($openReminderRows === []): ?>
                            <tr><td colspan="7" class="empty-cell">No open reminders linked to this booking right now.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($openReminderRows as $reminderRow): ?>
                            <?php
                            $reminderStatusKey = (string) ($reminderRow['statusKey'] ?? 'open');
                            $canTransitionReminder = in_array($reminderStatusKey, ['open', 'due'], true);
                            $reminderEditUrl = url('/workspace?booking_id=' . (int) ($workspaceBooking['id'] ?? 0) . '&reminder_edit=' . (int) ($reminderRow['id'] ?? 0) . '#dock-panel-reminders');
                            ?>
                            <tr>
                                <td>
                                    <?= e((string) ($reminderRow['type'] ?? 'Reminder')) ?>
                                    <?php if (!empty($reminderRow['isSystemGenerated'])): ?>
                                        <br><small>System</small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?= e((string) ($reminderRow['title'] ?? '')) ?>
                                    <?php if (trim((string) ($reminderRow['note'] ?? '')) !== ''): ?>
                                        <br><small><?= e((string) ($reminderRow['note'] ?? '')) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><?= e((string) ($reminderRow['linkedTo'] ?? 'Booking File')) ?></td>
                                <td><?= e((string) ($reminderRow['dueAt'] ?? '')) ?></td>
                                <td><?= e((string) ($reminderRow['channel'] ?? '')) ?></td>
                                <td><?= e((string) ($reminderRow['status'] ?? 'Open')) ?></td>
                                <td>
                                    <div class="legacy-reminder-actions">
                                        <?php if (empty($reminderRow['isSystemGenerated'])): ?>
                                            <a class="btn btn-sm" href="<?= e($reminderEditUrl) ?>">Edit</a>
                                        <?php endif; ?>
                                        <?php if ($canTransitionReminder): ?>
                                            <form method="post" action="<?= e(url('/workspace/reminders/complete')) ?>">
                                                <?= \App\Helpers\Csrf::input() ?>
                                                <input type="hidden" name="booking_id" value="<?= e((string) ($workspaceBooking['id'] ?? 0)) ?>">
                                                <input type="hidden" name="reminder_id" value="<?= e((string) ($reminderRow['id'] ?? 0)) ?>">
                                                <button class="btn btn-sm" type="submit">Complete</button>
                                            </form>
                                            <form method="post" action="<?= e(url('/workspace/reminders/dismiss')) ?>">
                                                <?= \App\Helpers\Csrf::input() ?>
                                                <input type="hidden" name="booking_id" value="<?= e((string) ($workspaceBooking['id'] ?? 0)) ?>">
                                                <input type="hidden" name="reminder_id" value="<?= e((string) ($reminderRow['id'] ?? 0)) ?>">
                                                <button class="btn btn-danger btn-sm" type="submit">Dismiss</button>
                                            </form>
                                        <?php else: ?>
                                            <span class="muted-text">Closed</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="legacy-payment-panel legacy-reminders-panel legacy-reminders-panel--alerts">
            <div class="legacy-payment-panel__title">RECEIVABLE ALERTS</div>
            <div class="legacy-payment-panel__body legacy-reminders-panel__body">
                <div
                    class="legacy-receivable-alerts"
                    data-receivable-alerts
                    data-default-scope="<?= e($defaultReceivableAlertScope) ?>"
                    data-counts-customer="<?= e(json_encode($receivableAlertCountsByScope['customer'] ?? ['total' => 0, 'overdue' => 0, 'dueToday' => 0, 'pending' => 0, 'missingDueDate' => 0], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?: '{}') ?>"
                    data-counts-booking="<?= e(json_encode($receivableAlertCountsByScope['booking'] ?? ['total' => 0, 'overdue' => 0, 'dueToday' => 0, 'pending' => 0, 'missingDueDate' => 0], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?: '{}') ?>"
                >
                    <div class="legacy-receivable-alerts__toolbar">
                        <div class="legacy-receivable-alerts__scope" role="group" aria-label="Receivable alert scope">
                            <?php foreach ($receivableAlertScopeLabels as $scopeKey => $scopeLabel): ?>
                                <button
                                    class="legacy-scope-toggle<?= $defaultReceivableAlertScope === $scopeKey ? ' is-active' : '' ?>"
                                    type="button"
                                    data-receivable-alert-scope-toggle
                                    data-scope="<?= e($scopeKey) ?>"
                                    aria-pressed="<?= $defaultReceivableAlertScope === $scopeKey ? 'true' : 'false' ?>"
                                ><?= e($scopeLabel) ?></button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <table class="legacy-table legacy-reminders-table" data-receivable-alert-table>
                    <thead>
                        <tr>
                            <th>Status</th>
                            <th>Booking</th>
                            <th>Passenger</th>
                            <th>Branch</th>
                            <th>Outstanding</th>
                            <th>Due Date</th>
                            <th>Service</th>
                            <th>Open</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($receivableAlertRows === []): ?>
                            <tr data-receivable-alert-empty-row><td colspan="8" class="empty-cell">No receivable alerts right now.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($receivableAlertRowsByScope as $scopeKey => $scopeRows): ?>
                            <?php foreach ($scopeRows as $alertRow): ?>
                            <tr
                                data-receivable-alert-row
                                data-receivable-alert-scope="<?= e($scopeKey) ?>"
                                class="<?= ! empty($alertRow['isCurrentBooking']) ? 'legacy-reminders-table__row--current-booking' : '' ?>"
                                <?= $defaultReceivableAlertScope === $scopeKey ? '' : 'hidden' ?>
                            >
                                <td><?= e((string) ($alertRow['category'] ?? 'Pending')) ?><br><small><?= e((string) ($alertRow['daysLabel'] ?? '')) ?></small></td>
                                <td>
                                    <?= e((string) ($alertRow['bookingReference'] ?? '')) ?>
                                    <?php if (! empty($alertRow['isCurrentBooking'])): ?>
                                        <br><small class="legacy-reminders-table__row-flag">Current Booking</small>
                                    <?php endif; ?>
                                </td>
                                <td><?= e((string) ($alertRow['customerName'] ?? '')) ?></td>
                                <td><?= e((string) ($alertRow['branchName'] ?? '')) ?></td>
                                <td><?= e((string) ($alertRow['currency'] ?? 'PKR') . ' ' . $formatMoney((float) ($alertRow['outstandingAmount'] ?? 0))) ?></td>
                                <td><?= e((string) (($alertRow['dueDate'] ?? '') !== '' ? $alertRow['dueDate'] : '-')) ?></td>
                                <td><?= e((string) (($alertRow['serviceSummary'] ?? '') !== '' ? $alertRow['serviceSummary'] : '-')) ?></td>
                                <td><a class="btn btn-sm" href="<?= e((string) ($alertRow['openUrl'] ?? '#')) ?>">Open</a></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            </div>
        </section>
    </div>

    <div id="dock-panel-documents" class="legacy-documents-shell">
        <section class="legacy-payment-panel legacy-documents-panel">
            <div class="legacy-payment-panel__title">DOCUMENTS</div>
            <div class="legacy-payment-panel__body legacy-documents-panel__body">
                <form
                    method="post"
                    action="<?= e(url('/workspace/documents/upload')) ?>"
                    enctype="multipart/form-data"
                    class="legacy-documents-form<?= $documentsReady ? '' : ' legacy-documents-form--disabled' ?>"
                    data-documents-upload-form
                    aria-disabled="<?= $documentsReady ? 'false' : 'true' ?>"
                >
                    <?= \App\Helpers\Csrf::input() ?>
                    <input type="hidden" name="booking_id" value="<?= e((string) ($workspaceBooking['id'] ?? 0)) ?>">

                    <label>
                        <span>Document Type</span>
                        <select name="document_type" required data-document-type-select <?= $documentsReady ? '' : 'disabled' ?>>
                            <?php foreach ($documentTypeOptions as $typeCode => $typeLabel): ?>
                                <option value="<?= e((string) $typeCode) ?>"><?= e((string) $typeLabel) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label class="legacy-documents-form__linked">
                        <span>Linked To</span>
                        <select name="linked_target" data-document-target-select <?= $documentsReady ? '' : 'disabled' ?>>
                            <?php if ($documentLinkTargets === []): ?>
                                <option value="<?= e('booking:' . (int) ($workspaceBooking['id'] ?? 0)) ?>">Booking File</option>
                            <?php else: ?>
                                <?php foreach ($documentLinkTargets as $target): ?>
                                    <option value="<?= e((string) ($target['value'] ?? '')) ?>"><?= e((string) ($target['label'] ?? 'Booking File')) ?></option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </label>

                    <label class="legacy-documents-form__wide">
                        <span>Document Title</span>
                        <input type="text" name="document_title" maxlength="190" required placeholder="Passport front scan / visa page / ticket copy" <?= $documentsReady ? '' : 'disabled' ?>>
                    </label>

                    <label>
                        <span>Replace Existing</span>
                        <select name="replace_document_id" <?= $documentsReady ? '' : 'disabled' ?>>
                            <option value="0">Add as new document</option>
                            <?php foreach ($replaceableDocuments as $replaceableDocument): ?>
                                <option value="<?= e((string) ($replaceableDocument['id'] ?? 0)) ?>">
                                    <?= e((string) (($replaceableDocument['title'] ?? 'Document') . ' / ' . ($documentTypeOptions[(string) ($replaceableDocument['document_type'] ?? '')] ?? ucwords(str_replace('_', ' ', (string) ($replaceableDocument['document_type'] ?? 'Document')))))) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label>
                        <span>Choose File</span>
                        <input type="file" name="document_file" accept=".pdf,.jpg,.jpeg,.png,.webp" required data-document-file-input <?= $documentsReady ? '' : 'disabled' ?>>
                    </label>

                    <label class="legacy-documents-form__wide legacy-documents-form__notes">
                        <span>Notes</span>
                        <input type="text" name="document_note" maxlength="4000" placeholder="Optional note about this file" <?= $documentsReady ? '' : 'disabled' ?>>
                    </label>

                    <div class="workspace-feedback workspace-feedback--inline legacy-documents-form__feedback" data-document-upload-feedback hidden></div>

                    <div class="legacy-documents-form__actions legacy-documents-form__upload-action">
                        <button class="btn btn-primary btn-sm" type="submit" <?= $documentsReady ? '' : 'disabled' ?>>Upload Document</button>
                    </div>
                </form>
                <script id="workspace-document-target-map" type="application/json"><?= json_encode($documentTargetOptionsByType, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
            </div>
        </section>

        <section class="legacy-payment-panel legacy-documents-panel legacy-documents-panel--register">
            <div class="legacy-payment-panel__title">CURRENT DOCUMENTS</div>
            <div class="legacy-payment-panel__body legacy-documents-panel__body">
                <table class="legacy-table">
                    <thead>
                        <tr>
                            <th>Type</th>
                            <th>Title</th>
                            <th>Linked To</th>
                            <th>File</th>
                            <th>Size</th>
                            <th>Status</th>
                            <th>Updated</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($documentRows === []): ?>
                            <tr><td colspan="8" class="empty-cell">No documents uploaded yet for this booking.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($documentRows as $documentRow): ?>
                            <?php $documentIsActive = (string) ($documentRow['statusRaw'] ?? 'active') === 'active'; ?>
                            <tr>
                                <td><?= e((string) ($documentRow['type'] ?? 'Document')) ?></td>
                                <td>
                                    <?= e((string) ($documentRow['title'] ?? '')) ?>
                                    <?php if (trim((string) ($documentRow['notes'] ?? '')) !== ''): ?>
                                        <br><small><?= e((string) $documentRow['notes']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><?= e((string) ($documentRow['linkedTo'] ?? 'Booking File')) ?></td>
                                <td><?= e((string) ($documentRow['fileName'] ?? '')) ?></td>
                                <td><?= e((string) ($documentRow['fileSize'] ?? '')) ?></td>
                                <td><?= e((string) ($documentRow['status'] ?? 'Active')) ?></td>
                                <td><?= e((string) ($documentRow['updatedAt'] ?? '')) ?></td>
                                <td>
                                    <div class="legacy-documents-actions">
                                        <?php if ($documentIsActive): ?>
                                            <a class="btn btn-sm" href="<?= e((string) ($documentRow['downloadUrl'] ?? '#')) ?>">Download</a>
                                            <form method="post" action="<?= e(url('/workspace/documents/revoke')) ?>" onsubmit="return confirm('Revoke this document from active use?');">
                                                <?= \App\Helpers\Csrf::input() ?>
                                                <input type="hidden" name="booking_id" value="<?= e((string) ($workspaceBooking['id'] ?? 0)) ?>">
                                                <input type="hidden" name="document_id" value="<?= e((string) ($documentRow['id'] ?? 0)) ?>">
                                                <button class="btn btn-danger btn-sm" type="submit">Revoke</button>
                                            </form>
                                        <?php else: ?>
                                            <span class="muted-text">No action</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="legacy-payment-panel legacy-reminders-panel legacy-reminders-panel--closed">
            <div class="legacy-payment-panel__title">CLOSED REMINDERS</div>
            <div class="legacy-payment-panel__body legacy-reminders-panel__body">
                <table class="legacy-table legacy-reminders-table" data-reminder-closed-table>
                    <thead>
                        <tr>
                            <th>Type</th>
                            <th>Task</th>
                            <th>Linked To</th>
                            <th>Due</th>
                            <th>Channel</th>
                            <th>Status</th>
                            <th>Closed</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($closedReminderRows === []): ?>
                            <tr><td colspan="7" class="empty-cell">No completed or dismissed reminders yet.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($closedReminderRows as $reminderRow): ?>
                            <tr>
                                <td>
                                    <?= e((string) ($reminderRow['type'] ?? 'Reminder')) ?>
                                    <?php if (!empty($reminderRow['isSystemGenerated'])): ?>
                                        <br><small>System</small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?= e((string) ($reminderRow['title'] ?? '')) ?>
                                    <?php if (trim((string) ($reminderRow['note'] ?? '')) !== ''): ?>
                                        <br><small><?= e((string) ($reminderRow['note'] ?? '')) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><?= e((string) ($reminderRow['linkedTo'] ?? 'Booking File')) ?></td>
                                <td><?= e((string) ($reminderRow['dueAt'] ?? '')) ?></td>
                                <td><?= e((string) ($reminderRow['channel'] ?? '')) ?></td>
                                <td><?= e((string) ($reminderRow['status'] ?? 'Closed')) ?></td>
                                <td><span class="muted-text">Closed</span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <div class="legacy-bottom-bar">
        <button class="legacy-bottom-button" type="button" data-workspace-action="new-booking">New</button>
        <button class="legacy-bottom-button" type="button" data-workspace-action="edit-booking" data-booking-gated-control <?= $workspaceBooking['id'] > 0 ? '' : 'disabled' ?>>Edit</button>
        <button class="legacy-bottom-button" type="button" data-workspace-action="delete-booking" data-booking-gated-control <?= $workspaceBooking['id'] > 0 ? '' : 'disabled' ?>>Delete</button>
        <button class="legacy-bottom-button" type="button" data-workspace-action="search-booking">Search</button>
        <button class="legacy-bottom-button" type="button" data-workspace-action="add-reminder">Reminder</button>
        <button class="legacy-bottom-button" type="button" data-workspace-action="print">Print</button>
        <button class="legacy-bottom-button" type="button" data-workspace-action="invoice-status"><?= e($bookingStatusOptions[$workspaceBooking['status']] ?? 'Inv. Status') ?></button>
        <button class="legacy-bottom-button" type="button" data-workspace-action="detail-remarks">Detail Remarks</button>
        <button class="legacy-bottom-button" type="button" data-workspace-action="supplier-settlement" data-booking-gated-control <?= $workspaceBooking['id'] > 0 ? '' : 'disabled' ?>>Suppliers</button>
        <a class="legacy-bottom-button <?= $workspaceBooking['id'] > 0 ? '' : 'is-disabled' ?>" href="<?= e($ledgerUrl) ?>" <?= $workspaceBooking['id'] > 0 ? 'target="_blank" rel="noopener"' : 'aria-disabled="true" tabindex="-1"' ?> data-booking-gated-control data-customer-ledger-link>Ledger</a>
        <button class="legacy-bottom-button legacy-bottom-button--receipt" type="button" data-workspace-action="payment-history" data-workflow-control="payment-history">Receipt</button>
    </div>

    <section class="customer-picker-modal" data-payment-history-modal hidden aria-hidden="true">
        <div class="customer-picker-modal__backdrop" data-payment-history-close></div>
        <div class="customer-picker-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="payment-history-title">
            <header class="customer-picker-modal__header">
                <div>
                    <strong id="payment-history-title">Payment History</strong>
                </div>
                <button class="btn btn-sm" type="button" data-payment-history-close>Close</button>
            </header>
            <div class="customer-picker-modal__body">
                <div class="workspace-feedback workspace-feedback--inline" data-payment-history-summary style="display:block;margin-bottom:12px;">
                    Current Invoice Total:
                    <strong><?= e($currentInvoiceAmount) ?></strong>
                    <span style="margin-left:12px;">Outstanding:
                        <strong><?= e($currentInvoiceBalance) ?></strong>
                    </span>
                </div>
                <div class="legacy-modal-grid">
                    <article>
                        <h3>Receipt History</h3>
                        <table class="legacy-table">
                            <thead><tr><th>Receipt</th><th>Date</th><th>Curr.</th><th>Amount Received</th><th>Returned / Refunded</th><th>Applied</th><th>Method</th><th>Status</th><th>Print</th><th>Payment Notes</th><th>Void</th><th>Recreate</th></tr></thead>
                            <tbody data-payment-history-receipts-body>
                            <?php foreach ($customerPaymentFoundation['receipts'] ?? [] as $receiptRow): ?>
                                <?php $receiptPrintUrl = $workspaceBooking['id'] > 0 ? url('/workspace/output?booking_id=' . $workspaceBooking['id'] . '&doc=customer_receipt&receipt_id=' . (int) ($receiptRow['id'] ?? 0)) : ''; ?>
                                <?php $receiptStatusRaw = str_replace(' ', '_', mb_strtolower(trim((string) ($receiptRow['statusRaw'] ?? $receiptRow['status'] ?? '')))); ?>
                                <?php $receiptRecreateUrl = url('/workspace?booking_id=' . (int) ($workspaceBooking['id'] ?? 0) . '&recreate_receipt_id=' . (int) ($receiptRow['id'] ?? 0) . '#dock-panel-payments'); ?>
                                <?php $receiptReturnedAmount = (float) ($receiptRow['returnedAmount'] ?? 0); ?>
                                <?php
                                $receiptDisplayAmount = (float) ($receiptRow['displayTenderedAmount'] ?? $receiptRow['tenderedAmount'] ?? $receiptRow['receivedAmount'] ?? 0);
                                $receiptDisplayMethod = (string) ($receiptRow['displayPaymentMethod'] ?? $receiptRow['paymentMethod'] ?? '');
                                $receiptDisplayStatus = (string) ($receiptRow['displayStatus'] ?? $receiptRow['statusRaw'] ?? $receiptRow['status'] ?? '');
                                $receiptIsInternalAdjustment = (bool) ($receiptRow['isInternalSettlementAdjustment'] ?? false);
                                ?>
                                <tr><td><?= e((string) $receiptRow['receiptNo']) ?></td><td><?= e((string) $receiptRow['receiptDate']) ?></td><td><?= e((string) $receiptRow['currency']) ?></td><td><?= e($formatMoney($receiptDisplayAmount)) ?></td><td><?= e($formatMoney($receiptReturnedAmount)) ?></td><td><?= e($formatMoney((float) $receiptRow['allocatedAmount'])) ?></td><td><?= e(ucwords(str_replace('_', ' ', $receiptDisplayMethod))) ?></td><td><?= e($receiptIsInternalAdjustment ? $receiptDisplayStatus : $formatStatusLabel($receiptDisplayStatus)) ?></td><td><?php if ($receiptPrintUrl !== ''): ?><a href="<?= e($receiptPrintUrl) ?>" target="_blank" rel="noopener">Print</a><?php else: ?>-<?php endif; ?></td><td><?php if ($receiptStatusRaw !== 'void' && ! $receiptIsInternalAdjustment): ?><form method="post" action="<?= e(url('/workspace/payments/receipts/metadata-save')) ?>" style="display:grid;gap:6px;min-width:190px;"><?= \App\Helpers\Csrf::input() ?><input type="hidden" name="booking_id" value="<?= e((string) ($workspaceBooking['id'] ?? 0)) ?>"><input type="hidden" name="customer_receipt_id" value="<?= e((string) ($receiptRow['id'] ?? 0)) ?>"><input type="text" name="receipt_reference_number" value="<?= e((string) ($receiptRow['referenceNumber'] ?? '')) ?>" placeholder="Payment reference" maxlength="100"><input type="text" name="receipt_bank_card_detail" value="<?= e((string) ($receiptRow['bankCardDetail'] ?? '')) ?>" placeholder="Bank / Payment details" maxlength="190"><input type="text" name="receipt_remarks" value="<?= e((string) ($receiptRow['remarks'] ?? '')) ?>" placeholder="Remarks / note" maxlength="4000"><button class="btn btn-sm" type="submit">Save Notes</button></form><?php else: ?>-<?php endif; ?></td><td><?php if ($canPostServiceEvents && $receiptStatusRaw !== 'void' && ! $receiptIsInternalAdjustment): ?><form method="post" action="<?= e(url('/workspace/payments/receipts/void')) ?>" onsubmit="return confirm('Void this receipt and reverse its allocations?');" style="display:grid;gap:6px;min-width:150px;"><?= \App\Helpers\Csrf::input() ?><input type="hidden" name="booking_id" value="<?= e((string) ($workspaceBooking['id'] ?? 0)) ?>"><input type="hidden" name="customer_receipt_id" value="<?= e((string) ($receiptRow['id'] ?? 0)) ?>"><input type="text" name="void_reason" value="" placeholder="Void reason" minlength="5" maxlength="1000" required><button class="btn btn-sm" type="submit">Void</button></form><?php else: ?>-<?php endif; ?></td><td><?php if ($receiptStatusRaw === 'void'): ?><a class="btn btn-sm" href="<?= e($receiptRecreateUrl) ?>">Recreate</a><?php else: ?>-<?php endif; ?></td></tr>
                            <?php endforeach; ?>
                            <?php if (($customerPaymentFoundation['receipts'] ?? []) === []): ?><tr><td colspan="12" class="empty-cell">No receipts recorded yet.</td></tr><?php endif; ?>
                            </tbody>
                        </table>
                    </article>
                    <article>
                        <h3>Allocation History</h3>
                        <table class="legacy-table">
                            <thead><tr><th>Allocated At</th><th>Receipt</th><th>Type</th><th>Booking</th><th>Svc Line</th><th>Service</th><th>Passenger</th><th>Allocated</th><th>Remaining After This Allocation</th><th>Trail</th></tr></thead>
                            <tbody data-payment-history-allocations-body>
                            <?php foreach ($paymentHistoryAllocations as $allocationRow): ?>
                                <?php
                                $allocationCurrency = (string) ($allocationRow['receivableCurrency'] ?? $allocationRow['currency'] ?? 'PKR');
                                $allocationPassenger = trim((string) ($allocationRow['passengerName'] ?? ''));
                                $allocationReceiptStatusRaw = str_replace(' ', '_', mb_strtolower(trim((string) ($allocationRow['receiptStatusRaw'] ?? ''))));
                                $allocationAllocatedAt = (string) ($allocationRow['allocatedAt'] ?? $allocationRow['allocated_at'] ?? $allocationRow['receiptDate'] ?? $allocationRow['receipt_date'] ?? '');
                                $allocationReceiptNo = (string) ($allocationRow['receiptNo'] ?? $allocationRow['receipt_no'] ?? 'N/A');
                                $allocationTypeRaw = (string) ($allocationRow['allocationType'] ?? $allocationRow['allocation_type'] ?? 'Allocated');
                                $allocationTypeLabel = $allocationTypeRaw === 'Previous Outstanding'
                                    ? 'Previous Balance'
                                    : ($allocationTypeRaw === 'Customer Credit / Unallocated' ? 'Customer Credit' : $allocationTypeRaw);
                                $allocationBookingReference = (string) ($allocationRow['bookingReference'] ?? $allocationRow['booking_reference'] ?? 'N/A');
                                $allocationServiceLineReference = (string) ($allocationRow['serviceLineReference'] ?? $allocationRow['service_line_reference'] ?? '');
                                $allocationServiceType = (string) ($allocationRow['serviceType'] ?? $allocationRow['service_type'] ?? 'Service');
                                $allocationAmount = (float) ($allocationRow['receivableAmountAllocated'] ?? $allocationRow['allocatedAmount'] ?? $allocationRow['allocated_amount'] ?? 0);
                                $allocationRemaining = (float) ($allocationRow['remainingAfterAllocation'] ?? $allocationRow['remaining_after_allocation'] ?? 0);
                                $allocationTrail = (string) ($allocationRow['allocationTrail'] ?? $allocationRow['allocation_trail'] ?? $allocationRow['trail'] ?? '');
                                if ($allocationTrail === '' && (string) ($allocationRow['receiptPurpose'] ?? $allocationRow['receipt_purpose'] ?? '') === 'customer_advance') {
                                    $allocationTrail = 'Customer advance applied to invoice.';
                                }
                                ?>
                                  <tr><td><?= e($allocationAllocatedAt) ?></td><td><?= e($allocationReceiptNo) ?></td><td><?= e($allocationTypeLabel) ?></td><td><?= e($allocationBookingReference) ?></td><td><?= e($allocationServiceLineReference !== '' ? $allocationServiceLineReference : 'N/A') ?></td><td><?= e($allocationServiceType) ?></td><td><?= e($allocationPassenger !== '' ? $allocationPassenger : (string) ($workspaceBooking['leadTravelerName'] ?? '')) ?></td><td><?= e($allocationCurrency) ?> <?= e($formatMoney($allocationAmount)) ?></td><td><?php if ($allocationReceiptStatusRaw === 'void'): ?>VOIDED<?php else: ?><?= e($allocationCurrency) ?> <?= e($formatMoney($allocationRemaining)) ?><?php endif; ?></td><td><?= e($allocationTrail) ?></td></tr>
                            <?php endforeach; ?>
                            <?php if ($paymentHistoryAllocations === []): ?><tr><td colspan="10" class="empty-cell">No allocations posted yet.</td></tr><?php endif; ?>
                            </tbody>
                        </table>
                    </article>
                </div>
            </div>
        </div>
    </section>

    <section class="customer-picker-modal" data-customer-dues-modal hidden aria-hidden="true" data-customer-dues-url="<?= e($duesFinderUrl) ?>">
        <div class="customer-picker-modal__backdrop" data-customer-dues-close></div>
        <div class="customer-picker-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="customer-dues-title" style="max-width:1180px;">
            <header class="customer-picker-modal__header">
                <div>
                    <strong id="customer-dues-title">Customer Dues Finder</strong>
                </div>
                <div class="customer-picker-modal__actions">
                    <a class="btn btn-primary btn-sm" href="<?= e($globalCustomerPaymentUrl) ?>">Global Customer Payment</a>
                    <button class="btn btn-primary btn-sm" type="button" data-customer-advance-open>Customer Advance</button>
                    <button class="btn btn-sm" type="button" data-customer-dues-close>Close</button>
                </div>
            </header>
            <div class="customer-picker-modal__body">
                <div class="station-form-grid station-form-grid--6 station-form-grid--inline">
                    <label class="station-field span-4"><span>Search Customer</span><input type="text" value="" placeholder="Name / mobile / passport / family ID" data-customer-dues-search></label>
                    <label class="station-field span-2"><span>Currency Filter</span><select data-customer-dues-currency-filter><option value="">All</option><option value="PKR">PKR</option><option value="AED">AED</option><option value="USD">USD</option></select></label>
                </div>
                <div class="workspace-feedback workspace-feedback--inline" data-customer-dues-feedback hidden></div>
                <div class="legacy-modal-grid top-gap">
                    <article>
                        <h3>Matching Customers</h3>
                        <table class="legacy-table">
                            <thead><tr><th>Customer</th><th>Passport</th><th>Mobile</th><th>Branch</th><th>Outstanding Balance</th><th>Action</th></tr></thead>
                            <tbody data-customer-dues-customers-body>
                                <tr><td colspan="6" class="empty-cell">Search a customer to load outstanding balances.</td></tr>
                            </tbody>
                        </table>
                    </article>
                    <article>
                        <h3>Outstanding Invoices</h3>
                        <div class="workspace-feedback workspace-feedback--inline" data-customer-dues-selected-summary style="display:block;margin-bottom:12px;">No customer selected.</div>
                        <table class="legacy-table">
                            <thead><tr><th>Booking / Invoice</th><th>Booking Date</th><th>Service Type</th><th>Passenger</th><th>Currency</th><th>Invoice Amount</th><th>Paid</th><th>Outstanding</th><th>Due Date</th><th>Status</th><th>Action</th></tr></thead>
                            <tbody data-customer-dues-invoices-body>
                                <tr><td colspan="11" class="empty-cell">No unpaid invoices loaded yet.</td></tr>
                            </tbody>
                        </table>
                    </article>
                </div>
            </div>
        </div>
    </section>

    <section class="customer-picker-modal" data-customer-advance-modal data-customer-advance-available-url="<?= e(url('/customers/advances/available')) ?>" hidden aria-hidden="true">
        <div class="customer-picker-modal__backdrop" data-customer-advance-close></div>
        <div class="customer-picker-modal__dialog customer-advance-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="customer-advance-title">
            <header class="customer-picker-modal__header">
                <div>
                    <strong id="customer-advance-title">Customer Advance</strong>
                </div>
                <button class="btn btn-sm" type="button" data-customer-advance-close>Close</button>
            </header>
            <div class="customer-picker-modal__body customer-advance-modal__body">
                <div class="customer-advance-toolbar">
                    <label class="station-field customer-advance-search-field"><span>Find Customer</span><input type="text" value="" placeholder="Name / mobile / passport / family ID" data-customer-advance-search></label>
                    <input type="hidden" value="" data-customer-advance-customer-name>
                    <div class="station-command-buttons station-command-buttons--end customer-advance-new-customer">
                        <button class="btn btn-sm" type="button" data-customer-advance-new-customer>New Customer</button>
                    </div>
                </div>
                <div class="customer-advance-results" data-customer-advance-results hidden></div>
                <div class="customer-advance-panel-grid">
                    <form method="post" action="<?= e(url('/customers/advances')) ?>" class="customer-advance-card" data-customer-advance-form>
                        <?= \App\Helpers\Csrf::input() ?>
                        <input type="hidden" name="traveler_id" value="" data-customer-advance-traveler-id>
                        <input type="hidden" name="return_to" value="<?= e((string) ($_SERVER['REQUEST_URI'] ?? '/workspace')) ?>" data-customer-advance-return-to>
                        <input type="hidden" name="print_after_save" value="0" data-customer-advance-print-after-save>
                        <input type="hidden" name="receipt_status" value="received">
                        <h3>Receive Advance</h3>
                        <div class="customer-advance-form-grid customer-advance-form-grid--receive">
                            <label class="station-field"><span>Branch</span><select name="branch_id" data-customer-advance-branch><?php foreach ($branchOptions as $branchRow): ?><option value="<?= e((string) $branchRow['id']) ?>" <?= (int) $branchRow['id'] === (int) $workspaceBooking['branchId'] ? 'selected' : '' ?>><?= e($compactBranchName((string) $branchRow['name'])) ?></option><?php endforeach; ?></select></label>
                            <label class="station-field"><span>Currency</span><select name="receipt_currency" data-customer-advance-currency><?php foreach (['PKR', 'AED', 'USD'] as $currencyOption): ?><option value="<?= e($currencyOption) ?>"><?= e($currencyOption) ?></option><?php endforeach; ?></select></label>
                            <label class="station-field"><span>Amount</span><input type="number" step="0.01" min="0" name="received_amount" value="0" data-customer-advance-amount></label>
                            <label class="station-field"><span>Method</span><select name="payment_method" data-customer-advance-method><?php foreach ($paymentMethodOptions as $paymentMethodValue => $paymentMethodLabel): ?><option value="<?= e((string) $paymentMethodValue) ?>" <?= (string) $paymentMethodValue === 'cash' ? 'selected' : '' ?>><?= e((string) $paymentMethodLabel) ?></option><?php endforeach; ?></select></label>
                            <label class="station-field customer-advance-account-field"><span>Deposit Account</span><select name="treasury_account_id" data-customer-advance-treasury-account><option value="">Select account</option></select></label>
                            <label class="station-field"><span>Date</span><input type="date" name="receipt_date" value="<?= e(date('Y-m-d')) ?>"></label>
                            <label class="station-field customer-advance-bank-detail" data-customer-advance-bank-detail-field hidden><span>Bank Details</span><input type="text" name="bank_card_detail" maxlength="190" placeholder="Bank / transaction detail"></label>
                            <label class="station-field"><span>Reference</span><input type="text" name="reference_number" maxlength="100"></label>
                            <label class="station-field customer-advance-remarks-field"><span>Remarks</span><input type="text" name="receipt_remarks" maxlength="4000"></label>
                        </div>
                        <div class="workspace-feedback workspace-feedback--inline" data-customer-advance-feedback hidden></div>
                        <div class="station-command-buttons station-command-buttons--end">
                            <button class="btn btn-primary btn-sm" type="submit" data-customer-advance-save-print>Save & Print</button>
                            <button class="btn btn-sm" type="submit" data-customer-advance-submit>Save Only</button>
                        </div>
                    </form>

                    <form method="post" action="<?= e(url('/customers/advances/refund')) ?>" class="customer-advance-card customer-advance-card--return" data-customer-advance-refund-form>
                        <?= \App\Helpers\Csrf::input() ?>
                        <input type="hidden" name="traveler_id" value="" data-customer-advance-refund-traveler-id>
                        <input type="hidden" name="return_to" value="<?= e((string) ($_SERVER['REQUEST_URI'] ?? '/workspace')) ?>" data-customer-advance-refund-return-to>
                        <h3>Return Advance</h3>
                        <div class="customer-advance-form-grid customer-advance-form-grid--refund">
                            <label class="station-field"><span>Branch</span><select name="branch_id" data-customer-advance-refund-branch><?php foreach ($branchOptions as $branchRow): ?><option value="<?= e((string) $branchRow['id']) ?>" <?= (int) $branchRow['id'] === (int) $workspaceBooking['branchId'] ? 'selected' : '' ?>><?= e($compactBranchName((string) $branchRow['name'])) ?></option><?php endforeach; ?></select></label>
                            <label class="station-field"><span>Currency</span><select name="receipt_currency" data-customer-advance-refund-currency><?php foreach (['PKR', 'AED', 'USD'] as $currencyOption): ?><option value="<?= e($currencyOption) ?>"><?= e($currencyOption) ?></option><?php endforeach; ?></select></label>
                            <label class="station-field customer-advance-account-field"><span>Advance</span><select name="advance_refund_receipt_id" data-customer-advance-refund-receipt><option value="">Select customer advance</option></select></label>
                            <label class="station-field"><span>Amount</span><input type="number" step="0.01" min="0" name="advance_refund_amount" value="0" data-customer-advance-refund-amount></label>
                            <label class="station-field"><span>Method</span><select name="advance_refund_method" data-customer-advance-refund-method><?php foreach ($paymentMethodOptions as $paymentMethodValue => $paymentMethodLabel): ?><option value="<?= e((string) $paymentMethodValue) ?>" <?= (string) $paymentMethodValue === 'cash' ? 'selected' : '' ?>><?= e((string) $paymentMethodLabel) ?></option><?php endforeach; ?></select></label>
                            <label class="station-field customer-advance-account-field"><span>Paid From</span><select name="advance_refund_treasury_account_id" data-customer-advance-refund-treasury-account><option value="">Select account</option></select></label>
                            <label class="station-field"><span>Date</span><input type="date" name="advance_refund_date" value="<?= e(date('Y-m-d')) ?>"></label>
                            <label class="station-field"><span>Reference</span><input type="text" name="advance_refund_reference_number" maxlength="100"></label>
                            <label class="station-field customer-advance-remarks-field"><span>Reason</span><input type="text" name="advance_refund_reason" maxlength="1000" placeholder="Reason"></label>
                            <label class="station-field customer-advance-remarks-field"><span>Remarks</span><input type="text" name="advance_refund_remarks" maxlength="4000"></label>
                        </div>
                        <div class="workspace-feedback workspace-feedback--inline" data-customer-advance-refund-feedback hidden></div>
                        <div class="station-command-buttons station-command-buttons--end">
                            <button class="btn btn-danger btn-sm" type="submit" data-customer-advance-refund-submit>Return Advance</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </section>

    <section class="customer-picker-modal" data-supplier-history-modal hidden aria-hidden="true" data-supplier-history-url="<?= e($supplierHistoryFinderUrl) ?>">
        <div class="customer-picker-modal__backdrop" data-supplier-history-close></div>
        <div class="customer-picker-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="supplier-history-title" style="max-width:1440px;">
            <header class="customer-picker-modal__header">
                <div>
                    <strong id="supplier-history-title">Supplier Payment Finder</strong>
                    <span>Search supplier name, supplier code, or booking reference, then open the booking supplier history even if the payable is already fully settled.</span>
                </div>
                <div class="customer-picker-modal__actions">
                    <a class="btn btn-primary btn-sm" href="<?= e($globalSupplierPaymentUrl) ?>">Global Supplier Payment</a>
                    <a class="btn btn-sm" href="<?= e($supplierPaymentFinderPrintUrl) ?>" target="_blank" rel="noopener">Print / PDF</a>
                    <button class="btn btn-sm" type="button" data-supplier-history-close>Close</button>
                </div>
            </header>
            <div class="customer-picker-modal__body">
                <div class="station-form-grid station-form-grid--6 station-form-grid--inline">
                    <label class="station-field span-6"><span>Search Supplier / Booking</span><input type="text" value="" placeholder="Supplier name / supplier code / booking reference" data-supplier-history-search></label>
                </div>
                <div class="workspace-feedback workspace-feedback--inline" data-supplier-history-feedback hidden></div>
                <div class="legacy-modal-grid top-gap">
                    <article>
                        <h3>Matching Supplier Bookings</h3>
                        <table class="legacy-table">
                            <thead><tr><th>Supplier</th><th>Booking / Invoice</th><th>Passenger</th><th>Route</th><th>Booking Date</th><th>Branch</th><th>Curr.</th><th>Total Payable</th><th>Paid</th><th>Balance</th><th>Due Date</th><th>Status</th><th>Action</th></tr></thead>
                            <tbody data-supplier-history-results-body>
                                <tr><td colspan="13" class="empty-cell">Search a supplier or booking to load supplier payment history.</td></tr>
                            </tbody>
                        </table>
                    </article>
                </div>
            </div>
        </div>
    </section>

    <section class="customer-picker-modal" data-payment-exchange-modal hidden aria-hidden="true">
        <div class="customer-picker-modal__backdrop" data-payment-exchange-close></div>
        <div class="customer-picker-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="payment-exchange-title">
            <header class="customer-picker-modal__header">
                <div>
                    <strong id="payment-exchange-title">Exchange Settlement</strong>
                    <span>Confirm current-invoice cross-currency settlement using today&apos;s exact rate.</span>
                </div>
                <button class="btn btn-sm" type="button" data-payment-exchange-close>Close</button>
            </header>
            <div class="customer-picker-modal__body">
                <div class="station-form-grid station-form-grid--6 station-form-grid--inline">
                    <label class="station-field span-3"><span>Current Invoice No.</span><input type="text" value="<?= e($invoiceNoLabel) ?>" readonly data-payment-exchange-invoice-no></label>
                    <label class="station-field span-3"><span>Invoice Currency</span><input type="text" value="<?= e($invoiceCurrency) ?>" readonly data-payment-exchange-invoice-currency></label>
                    <label class="station-field span-6" hidden><span>Settlement Target</span><select data-payment-exchange-target></select></label>
                    <label class="station-field span-2"><span>Target Currency</span><input type="text" value="" readonly data-payment-exchange-target-currency></label>
                    <label class="station-field span-2"><span>Target Balance</span><input type="text" value="" readonly data-payment-exchange-target-balance></label>
                    <label class="station-field span-2"><span>Payment Currency</span><select data-payment-exchange-payment-currency><?php foreach (['PKR', 'AED', 'USD'] as $currencyOption): ?><option value="<?= e($currencyOption) ?>"><?= e($currencyOption) ?></option><?php endforeach; ?></select></label>
                    <label class="station-field span-2"><span>Rate Date</span><input type="text" value="<?= e(date('Y-m-d')) ?>" readonly data-payment-exchange-rate-date-display></label>
                    <label class="station-field span-2" data-payment-exchange-rate-row hidden><span data-payment-exchange-rate-label>Exchange Rate</span><input type="number" step="0.00000001" value="" data-payment-exchange-rate-input></label>
                    <label class="station-field span-2" data-payment-exchange-payment-amount-row hidden><span data-payment-exchange-payment-amount-label>Amount to Pay</span><input type="text" value="" readonly data-payment-exchange-payment-amount></label>
                </div>
                <div class="workspace-feedback workspace-feedback--inline" data-payment-exchange-rate-help hidden></div>
                <div class="workspace-feedback workspace-feedback--inline" data-payment-exchange-feedback hidden></div>
                <div class="legacy-modal-grid top-gap">
                    <article>
                        <div class="station-command-buttons">
                            <button class="btn btn-primary btn-sm" type="button" data-payment-exchange-confirm>Confirm Settlement</button>
                            <button class="btn btn-sm" type="button" data-payment-exchange-close>Cancel</button>
                        </div>
                    </article>
                </div>
            </div>
        </div>
    </section>

    <section id="dock-panel-suppliers" class="customer-picker-modal" data-supplier-settlement-modal hidden aria-hidden="true">
        <div class="customer-picker-modal__backdrop" data-supplier-settlement-close></div>
        <div class="customer-picker-modal__dialog postpaid-supplier-settlement-modal" role="dialog" aria-modal="true" aria-labelledby="supplier-settlement-title">
            <header class="customer-picker-modal__header">
                <div>
                    <strong id="supplier-settlement-title">Postpaid Supplier Settlement</strong>
                </div>
                <button class="btn btn-sm" type="button" data-supplier-settlement-close>Close</button>
            </header>
            <div class="customer-picker-modal__body">
                <div class="legacy-modal-grid">
                    <article>
                        <h3>Supplier Summary</h3>
                        <div class="legacy-highlight-strip">
                            <div class="legacy-highlight legacy-highlight--red"><span>Total Supplier Payable</span><strong><?= e($formatCurrencyTotals($supplierOutstandingTotals)) ?></strong></div>
                            <div class="legacy-highlight legacy-highlight--orange"><span>Advance Used</span><strong><?= e($formatCurrencyTotals($supplierAdvanceAppliedTotals)) ?></strong></div>
                            <div class="legacy-highlight legacy-highlight--green"><span>Prepaid Available</span><strong><?= e($formatCurrencyTotals($supplierAdvanceBalanceTotals)) ?></strong></div>
                            <div class="legacy-highlight legacy-highlight--pink"><span>Paid to Supplier</span><strong><?= e($formatCurrencyTotals($supplierPaidTotals)) ?></strong></div>
                            <div class="legacy-highlight legacy-highlight--red"><span>Supplier Balance</span><strong><?= e($formatCurrencyTotals($supplierOutstandingTotals)) ?></strong></div>
                        </div>
                        <table class="legacy-table">
                            <thead><tr><th>Code</th><th>Supplier</th><th>Mode</th><th>Curr.</th><th>Gross</th><th>Prepaid Available</th><th>Paid</th><th>Balance</th></tr></thead>
                            <tbody>
                            <?php foreach (($supplierFoundation['suppliers'] ?? []) as $supplierRow): ?>
                                <tr>
                                    <td><?= e((string) ($supplierRow['code'] ?? '')) ?></td>
                                    <td><?= e((string) ($supplierRow['name'] ?? '')) ?></td>
                                    <td><?= e((string) ($supplierModeOptions[(string) ($supplierRow['mode'] ?? '')] ?? ucwords(str_replace('_', ' ', (string) ($supplierRow['mode'] ?? ''))))) ?></td>
                                    <td><?= e((string) ($supplierRow['currency'] ?? '')) ?></td>
                                    <td><?= e($formatMoney((float) ($supplierRow['grossObligation'] ?? 0))) ?></td>
                                    <td><?= e($formatMoney((float) ($supplierRow['advanceBalance'] ?? 0))) ?></td>
                                    <td><?= e($formatMoney((float) ($supplierRow['totalPaid'] ?? 0))) ?></td>
                                    <td><?= e($formatMoney((float) ($supplierRow['balanceDue'] ?? 0))) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (($supplierFoundation['suppliers'] ?? []) === []): ?><tr><td colspan="8" class="empty-cell">No suppliers linked to this invoice yet.</td></tr><?php endif; ?>
                            </tbody>
                        </table>
                    </article>
                    <article>
                        <h3>Service Supplier Position</h3>
                        <table class="legacy-table">
                            <thead><tr><th>Supplier</th><th>Svc Line</th><th>Curr.</th><th>Gross</th><th>Adv. Used</th><th>Paid</th><th>Balance</th><th>Due</th><th>Status</th></tr></thead>
                            <tbody>
                            <?php foreach (($supplierFoundation['obligations'] ?? []) as $obligationRow): ?>
                                <tr>
                                    <td><?= e((string) ($obligationRow['supplier'] ?? '')) ?></td>
                                    <td><?= e((string) ($obligationRow['serviceLineReference'] ?? '')) ?></td>
                                    <td><?= e((string) ($obligationRow['currency'] ?? '')) ?></td>
                                    <td><?= e($formatMoney((float) ($obligationRow['grossAmount'] ?? 0))) ?></td>
                                    <td><?= e($formatMoney((float) ($obligationRow['advanceAppliedAmount'] ?? 0))) ?></td>
                                    <td><?= e($formatMoney((float) ($obligationRow['paymentAllocatedAmount'] ?? 0))) ?></td>
                                    <td><?= e($formatMoney((float) ($obligationRow['balanceDueAmount'] ?? 0))) ?></td>
                                    <td><?= e((string) ($obligationRow['dueDate'] ?? '')) ?></td>
                                    <td><?= e((string) ($obligationRow['status'] ?? '')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (($supplierFoundation['obligations'] ?? []) === []): ?><tr><td colspan="9" class="empty-cell">No supplier obligations recorded yet.</td></tr><?php endif; ?>
                            </tbody>
                        </table>
                    </article>
                    <article>
                        <h3>Pay Outstanding Supplier Balance</h3>
                        <?php if ($supplierPaymentRecreateDraft !== null): ?>
                            <div class="workspace-feedback workspace-feedback--inline" style="display:block;margin-bottom:12px;">
                                Draft loaded from voided supplier payment <strong><?= e((string) ($supplierPaymentRecreateDraft['paymentNo'] ?? '')) ?></strong>.
                                Review values below, select the payable rows again, and save to create a brand new payment.
                                <a href="<?= e($supplierRecreateClearUrl) ?>" style="margin-left:8px;">Clear Draft</a>
                            </div>
                        <?php endif; ?>
                        <form method="post" action="<?= e(url('/workspace/suppliers/payments/simple-save')) ?>" data-simple-postpaid-form>
                            <?= \App\Helpers\Csrf::input() ?>
                            <input type="hidden" name="booking_id" value="<?= e((string) $workspaceBooking['id']) ?>">
                            <input type="hidden" name="branch_id" value="<?= e((string) $workspaceBooking['branchId']) ?>">
                            <input type="hidden" name="supplier_payment_currency" value="" data-simple-postpaid-currency-input>
                            <table class="legacy-table top-gap">
                                <thead><tr><th><label class="global-settlement-select-all">Select <input type="checkbox" data-simple-postpaid-select-all aria-label="Select all supplier payable rows"></label></th><th>Supplier</th><th>Svc Line</th><th>Curr.</th><th>Due</th><th>Outstanding</th></tr></thead>
                                <tbody>
                                <?php foreach (($supplierFoundation['openObligations'] ?? []) as $openObligation): ?>
                                    <tr>
                                        <td><input type="checkbox" name="simple_supplier_obligation_id[]" value="<?= e((string) ($openObligation['id'] ?? 0)) ?>" data-simple-postpaid-select data-supplier-id="<?= e((string) ($openObligation['supplierId'] ?? 0)) ?>" data-supplier-name="<?= e((string) ($openObligation['supplier'] ?? '')) ?>" data-currency="<?= e((string) ($openObligation['currency'] ?? '')) ?>" data-balance="<?= e((string) number_format((float) ($openObligation['netPayableAmount'] ?? 0), 2, '.', '')) ?>"></td>
                                        <td><?= e((string) ($openObligation['supplier'] ?? '')) ?></td>
                                        <td><?= e((string) ($openObligation['serviceLineReference'] ?? '')) ?></td>
                                        <td><?= e((string) ($openObligation['currency'] ?? '')) ?></td>
                                        <td><?= e((string) ($openObligation['dueDate'] ?? '')) ?></td>
                                        <td><?= e($formatMoney((float) ($openObligation['netPayableAmount'] ?? 0))) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (($supplierFoundation['openObligations'] ?? []) === []): ?><tr><td colspan="6" class="empty-cell">No open supplier obligations to settle.</td></tr><?php endif; ?>
                                </tbody>
                            </table>
                            <div class="supplier-simple-payment-feedback top-gap" data-simple-postpaid-feedback><?= ($supplierFoundation['openObligations'] ?? []) === [] ? 'No open supplier payable is available to settle.' : 'Please select at least one supplier payable.' ?></div>
                            <div class="station-form-grid station-form-grid--6 station-form-grid--inline">
                                <label class="station-field span-3"><span>Selected Supplier(s)</span><input type="text" value="" data-simple-postpaid-supplier-display readonly placeholder="Select payable rows"></label>
                                <label class="station-field span-2"><span>Date</span><input type="date" name="supplier_payment_date" value="<?= e($supplierDraftDate) ?>"></label>
                                <label class="station-field span-1"><span>Currency</span><input type="text" value="" data-simple-postpaid-currency-display readonly placeholder="--"></label>
                                <label class="station-field span-2"><span>Amount</span><input type="number" step="0.01" name="supplier_paid_amount" value="<?= e($supplierDraftAmount) ?>" data-simple-postpaid-amount></label>
                                <label class="station-field span-2"><span>Method</span><select name="supplier_payment_method"><?php foreach ($paymentMethodOptions as $paymentMethodValue => $paymentMethodLabel): ?><option value="<?= e((string) $paymentMethodValue) ?>" <?= $supplierDraftMethod === (string) $paymentMethodValue ? 'selected' : '' ?>><?= e((string) $paymentMethodLabel) ?></option><?php endforeach; ?></select></label>
                                <label class="station-field span-2" data-supplier-treasury-row hidden><span>Source Account</span><select name="supplier_treasury_account_id" data-supplier-treasury-select><option value="">Select account</option></select></label>
                                <label class="station-field span-2"><span>Reference</span><input type="text" name="supplier_reference_number" value="<?= e($supplierDraftReferenceNumber) ?>"></label>
                                <label class="station-field span-3"><span>Bank / Card Detail</span><input type="text" name="supplier_bank_card_detail" value="<?= e($supplierDraftBankCardDetail) ?>"></label>
                                <label class="station-field span-3"><span>Remarks</span><input type="text" name="supplier_payment_remarks" value="<?= e($supplierDraftRemarks) ?>"></label>
                            </div>
                            <div class="supplier-simple-payment-summary top-gap">
                                <span>Selected outstanding total:</span>
                                <strong data-simple-postpaid-total>0</strong>
                            </div>
                            <div class="station-command-buttons top-gap"><button class="btn btn-primary btn-sm" type="submit" data-simple-postpaid-submit data-booking-gated-control <?= $workspaceBooking['id'] > 0 ? '' : 'disabled' ?>>Save Payment</button></div>
                        </form>
                    </article>
                    <article>
                        <details class="supplier-dashboard-advanced">
                            <summary>Advanced: Manual Allocation</summary>
                            <div class="supplier-dashboard-advanced__body">
                                <article>
                                    <h3>Record Supplier Payment</h3>
                                    <?php if ($supplierPaymentRecreateDraft !== null): ?>
                                        <div class="workspace-feedback workspace-feedback--inline" style="display:block;margin-bottom:12px;">
                                            Recreate helper is active for supplier payment <strong><?= e((string) ($supplierPaymentRecreateDraft['paymentNo'] ?? '')) ?></strong>.
                                            This is a fresh draft only; the old payment stays voided and unchanged.
                                        </div>
                                    <?php endif; ?>
                                    <form method="post" action="<?= e(url('/workspace/suppliers/payments/save')) ?>">
                                        <?= \App\Helpers\Csrf::input() ?>
                                        <input type="hidden" name="booking_id" value="<?= e((string) $workspaceBooking['id']) ?>">
                                        <input type="hidden" name="branch_id" value="<?= e((string) $workspaceBooking['branchId']) ?>">
                                        <div class="station-form-grid station-form-grid--6 station-form-grid--inline">
                                            <label class="station-field span-2"><span>Supplier</span><input type="text" name="supplier_name" list="service-supplier-options" value="<?= e($supplierDraftName) ?>"></label>
                                            <label class="station-field span-2"><span>Date</span><input type="date" name="supplier_payment_date" value="<?= e($supplierDraftDate) ?>"></label>
                                            <label class="station-field span-2"><span>Currency</span><select name="supplier_payment_currency"><?php foreach (['PKR', 'AED', 'USD'] as $currencyOption): ?><option value="<?= e($currencyOption) ?>" <?= $supplierDraftCurrency === $currencyOption ? 'selected' : '' ?>><?= e($currencyOption) ?></option><?php endforeach; ?></select></label>
                                            <label class="station-field span-2"><span>Amount</span><input type="number" step="0.01" name="supplier_paid_amount" value="<?= e($supplierDraftAmount) ?>"></label>
                                            <label class="station-field span-2"><span>Method</span><select name="supplier_payment_method"><?php foreach ($paymentMethodOptions as $paymentMethodValue => $paymentMethodLabel): ?><option value="<?= e((string) $paymentMethodValue) ?>" <?= $supplierDraftMethod === (string) $paymentMethodValue ? 'selected' : '' ?>><?= e((string) $paymentMethodLabel) ?></option><?php endforeach; ?></select></label>
                                            <label class="station-field span-2" data-supplier-treasury-row hidden><span>Source Account</span><select name="supplier_treasury_account_id" data-supplier-treasury-select><option value="">Select account</option></select></label>
                                            <label class="station-field span-2"><span>Status</span><select name="supplier_payment_status"><option value="paid">Paid</option><?php if ($canPostServiceEvents): ?><option value="void">Void</option><?php endif; ?></select></label>
                                            <label class="station-field span-3"><span>Reference</span><input type="text" name="supplier_reference_number" value="<?= e($supplierDraftReferenceNumber) ?>"></label>
                                            <label class="station-field span-3"><span>Bank / Card Detail</span><input type="text" name="supplier_bank_card_detail" value="<?= e($supplierDraftBankCardDetail) ?>"></label>
                                            <label class="station-field span-2"><span>Charges</span><input type="number" step="0.01" name="supplier_charges_amount" value="0.00"></label>
                                            <label class="station-field span-4"><span>Remarks</span><input type="text" name="supplier_payment_remarks" value="<?= e($supplierDraftRemarks) ?>"></label>
                                        </div>
                                    <div class="station-command-buttons top-gap"><button class="btn btn-sm" type="submit" data-booking-gated-control <?= $workspaceBooking['id'] > 0 ? '' : 'disabled' ?>>Save Manual Supplier Payment</button></div>
                                    </form>
                                </article>
                                <article>
                                    <h3>Allocate Supplier Payment</h3>
                                    <form method="post" action="<?= e(url('/workspace/suppliers/payments/allocate')) ?>">
                                        <?= \App\Helpers\Csrf::input() ?>
                                        <input type="hidden" name="booking_id" value="<?= e((string) $workspaceBooking['id']) ?>">
                                        <div class="station-form-grid station-form-grid--6 station-form-grid--inline">
                                            <label class="station-field span-4"><span>Unallocated Payment</span><select name="supplier_payment_id"><?php foreach (($supplierFoundation['allocatablePayments'] ?? []) as $allocatablePayment): ?><option value="<?= e((string) ($allocatablePayment['id'] ?? 0)) ?>"><?= e((string) (($allocatablePayment['paymentNo'] ?? '') . ' / ' . ($allocatablePayment['supplier'] ?? '') . ' / ' . ($allocatablePayment['currency'] ?? '') . ' ' . $formatMoney((float) ($allocatablePayment['unallocatedAmount'] ?? 0)))) ?></option><?php endforeach; ?></select></label>
                                        </div>
                                        <table class="legacy-table top-gap">
                                            <thead><tr><th>Supplier</th><th>Svc Line</th><th>Curr.</th><th>Balance</th><th>Allocate</th><th>Note</th></tr></thead>
                                            <tbody>
                                            <?php foreach (($supplierFoundation['openObligations'] ?? []) as $openObligation): ?>
                                                <tr>
                                                    <td><?= e((string) ($openObligation['supplier'] ?? '')) ?></td>
                                                    <td><?= e((string) ($openObligation['serviceLineReference'] ?? '')) ?></td>
                                                    <td><?= e((string) ($openObligation['currency'] ?? '')) ?></td>
                                                    <td><?= e($formatMoney((float) ($openObligation['netPayableAmount'] ?? 0))) ?></td>
                                                    <td><input type="hidden" name="supplier_allocation_obligation_id[]" value="<?= e((string) ($openObligation['id'] ?? 0)) ?>"><input type="number" step="0.01" name="supplier_allocation_amount[]" value="0.00"></td>
                                                    <td><input type="text" name="supplier_allocation_note[]" value=""></td>
                                                </tr>
                                            <?php endforeach; ?>
                                            <?php if (($supplierFoundation['openObligations'] ?? []) === []): ?><tr><td colspan="6" class="empty-cell">No open supplier obligations to allocate.</td></tr><?php endif; ?>
                                            </tbody>
                                        </table>
                                    <div class="station-command-buttons top-gap"><button class="btn btn-sm" type="submit" data-booking-gated-control <?= $workspaceBooking['id'] > 0 ? '' : 'disabled' ?>>Allocate Supplier Payment</button></div>
                                    </form>
                                </article>
                                <article>
                                    <h3>Use Different-Currency Supplier Advance</h3>
                                    <form method="post" action="<?= e(url('/workspace/suppliers/advances/apply-fx')) ?>" onsubmit="return confirm('Use a supplier advance in a different currency? Confirm the exact rate before posting.');">
                                        <?= \App\Helpers\Csrf::input() ?>
                                        <input type="hidden" name="booking_id" value="<?= e((string) $workspaceBooking['id']) ?>">
                                        <div class="station-form-grid station-form-grid--6 station-form-grid--inline">
                                            <label class="station-field span-3"><span>Payable to Cover</span><select name="supplier_obligation_id">
                                                <?php foreach (($supplierFoundation['openObligations'] ?? []) as $openObligation): ?>
                                                    <?php if ((float) ($openObligation['netPayableAmount'] ?? 0) > 0): ?>
                                                        <option value="<?= e((string) ($openObligation['id'] ?? 0)) ?>"><?= e((string) (($openObligation['supplier'] ?? '') . ' / ' . ($openObligation['serviceLineReference'] ?? '') . ' / ' . ($openObligation['currency'] ?? '') . ' ' . $formatMoney((float) ($openObligation['netPayableAmount'] ?? 0)))) ?></option>
                                                    <?php endif; ?>
                                                <?php endforeach; ?>
                                            </select></label>
                                            <label class="station-field span-3"><span>Advance to Use</span><select name="supplier_advance_id">
                                                <?php foreach (($supplierFoundation['advances'] ?? []) as $advanceRow): ?>
                                                    <?php if ((float) ($advanceRow['availableAmount'] ?? 0) > 0): ?>
                                                        <option value="<?= e((string) ($advanceRow['id'] ?? 0)) ?>"><?= e((string) (($advanceRow['supplier'] ?? '') . ' / ' . ($advanceRow['currency'] ?? '') . ' available ' . $formatMoney((float) ($advanceRow['availableAmount'] ?? 0)))) ?></option>
                                                    <?php endif; ?>
                                                <?php endforeach; ?>
                                            </select></label>
                                            <label class="station-field span-2"><span>Amount to Cover</span><input type="number" step="0.01" name="advance_apply_amount" value="0.00"></label>
                                            <label class="station-field span-2"><span>Rate Date</span><input type="date" name="exchange_rate_effective_date" value="<?= e(date('Y-m-d')) ?>"></label>
                                            <label class="station-field span-2"><span>Rate</span><input type="number" step="0.00000001" name="exchange_rate" value="0.00000000" placeholder="1 advance = payable"></label>
                                            <label class="station-field span-6"><span>Note</span><input type="text" name="application_note" value="" placeholder="Example: Approved AED advance use for PKR payable"></label>
                                        </div>
                                        <div class="station-command-buttons top-gap"><button class="btn btn-sm" type="submit" data-booking-gated-control <?= $workspaceBooking['id'] > 0 ? '' : 'disabled' ?>>Apply Different-Currency Advance</button></div>
                                    </form>
                                </article>
                            </div>
                        </details>
                    </article>
                    <article>
                        <h3>Supplier Payments</h3>
                        <table class="legacy-table">
                            <thead><tr><th>Payment No.</th><th>Supplier</th><th>Date</th><th>Curr.</th><th>Paid</th><th>Allocated</th><th>Open</th><th>Source</th><th>Status</th><th>Print</th><th>Meta Edit</th><th>Void</th><th>Recreate</th></tr></thead>
                            <tbody>
                            <?php foreach (($supplierFoundation['payments'] ?? []) as $paymentRow): ?>
                                <?php
                                $supplierPaymentStatusRaw = str_replace(' ', '_', mb_strtolower(trim((string) ($paymentRow['statusRaw'] ?? $paymentRow['status'] ?? ''))));
                                $supplierPaymentPrintUrl = $workspaceBooking['id'] > 0
                                    ? url('/workspace/output?booking_id=' . $workspaceBooking['id'] . '&doc=supplier_voucher&supplier_payment_id=' . (int) ($paymentRow['id'] ?? 0))
                                    : '';
                                $supplierPaymentRecreateUrl = url('/workspace?booking_id=' . (int) ($workspaceBooking['id'] ?? 0) . '&recreate_supplier_payment_id=' . (int) ($paymentRow['id'] ?? 0) . '#dock-panel-suppliers');
                                ?>
                                <tr>
                                    <td><?= e((string) ($paymentRow['paymentNo'] ?? '')) ?></td>
                                    <td><?= e((string) ($paymentRow['supplier'] ?? '')) ?></td>
                                    <td><?= e((string) ($paymentRow['paymentDate'] ?? '')) ?></td>
                                    <td><?= e((string) ($paymentRow['currency'] ?? '')) ?></td>
                                    <td><?= e($formatMoney((float) ($paymentRow['paidAmount'] ?? 0))) ?></td>
                                    <td><?= e($formatMoney((float) ($paymentRow['allocatedAmount'] ?? 0))) ?></td>
                                    <td><?= e($formatMoney((float) ($paymentRow['unallocatedAmount'] ?? 0))) ?></td>
                                    <td><?= e((string) (($paymentRow['treasuryAccountName'] ?? '') !== '' ? $paymentRow['treasuryAccountName'] : ucwords(str_replace('_', ' ', (string) ($paymentRow['paymentMethod'] ?? ''))))) ?></td>
                                    <td><?= e($formatStatusLabel((string) ($paymentRow['statusRaw'] ?? $paymentRow['status'] ?? ''))) ?></td>
                                    <td><?php if ($supplierPaymentPrintUrl !== ''): ?><a href="<?= e($supplierPaymentPrintUrl) ?>" target="_blank" rel="noopener">Print</a><?php else: ?>-<?php endif; ?></td>
                                    <td><form method="post" action="<?= e(url('/workspace/suppliers/payments/metadata-save')) ?>" style="display:grid;gap:6px;min-width:190px;"><?= \App\Helpers\Csrf::input() ?><input type="hidden" name="booking_id" value="<?= e((string) ($workspaceBooking['id'] ?? 0)) ?>"><input type="hidden" name="supplier_payment_id" value="<?= e((string) ($paymentRow['id'] ?? 0)) ?>"><input type="text" name="supplier_reference_number" value="<?= e((string) ($paymentRow['referenceNumber'] ?? '')) ?>" placeholder="Reference" maxlength="100"><input type="text" name="supplier_bank_card_detail" value="<?= e((string) ($paymentRow['bankCardDetail'] ?? '')) ?>" placeholder="Bank / Card detail" maxlength="190"><input type="text" name="supplier_payment_remarks" value="<?= e((string) ($paymentRow['remarks'] ?? '')) ?>" placeholder="Remarks" maxlength="4000"><button class="btn btn-sm" type="submit">Save Notes</button></form></td>
                                    <td><?php if ($canPostServiceEvents && $supplierPaymentStatusRaw !== 'void'): ?><form method="post" action="<?= e(url('/workspace/suppliers/payments/void')) ?>" onsubmit="return confirm('Void this supplier payment and reverse its allocations?');" style="display:grid;gap:6px;min-width:150px;"><?= \App\Helpers\Csrf::input() ?><input type="hidden" name="booking_id" value="<?= e((string) ($workspaceBooking['id'] ?? 0)) ?>"><input type="hidden" name="supplier_payment_id" value="<?= e((string) ($paymentRow['id'] ?? 0)) ?>"><input type="text" name="void_reason" value="" placeholder="Void reason" minlength="5" maxlength="1000" required><button class="btn btn-sm" type="submit">Void</button></form><?php else: ?>-<?php endif; ?></td>
                                    <td><?php if ($supplierPaymentStatusRaw === 'void'): ?><a class="btn btn-sm" href="<?= e($supplierPaymentRecreateUrl) ?>">Recreate</a><?php else: ?>-<?php endif; ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (($supplierFoundation['payments'] ?? []) === []): ?><tr><td colspan="13" class="empty-cell">No supplier payments recorded for this invoice yet.</td></tr><?php endif; ?>
                            </tbody>
                        </table>
                    </article>
                </div>
            </div>
        </div>
    </section>

    <section class="customer-picker-modal customer-picker-modal--child" data-global-prepaid-supplier-modal hidden aria-hidden="true">
        <div class="customer-picker-modal__backdrop" data-global-prepaid-supplier-close></div>
        <div class="customer-picker-modal__dialog global-prepaid-supplier-modal" role="dialog" aria-modal="true" aria-labelledby="global-prepaid-supplier-title">
            <header class="customer-picker-modal__header global-prepaid-supplier-modal__header">
                <div>
                    <strong id="global-prepaid-supplier-title">Prepaid Supplier Payment</strong>
                </div>
                <button class="btn btn-sm" type="button" data-global-prepaid-supplier-close>Close</button>
            </header>
            <div class="customer-picker-modal__body">
                <div class="legacy-modal-grid">
                    <article>
                        <h3>Record Global Supplier Advance</h3>
                        <form method="post" action="<?= e(url('/suppliers/advances/save')) ?>" data-global-prepaid-supplier-form>
                            <?= \App\Helpers\Csrf::input() ?>
                            <div class="station-form-grid station-form-grid--6 station-form-grid--inline">
                                <label class="station-field span-2"><span>Branch</span><select name="branch_id" data-global-prepaid-branch><?php foreach ($branchOptions as $branchRow): ?><option value="<?= e((string) $branchRow['id']) ?>" <?= (int) $branchRow['id'] === $globalPrepaidDefaultBranchId ? 'selected' : '' ?>><?= e((string) $branchRow['name']) ?></option><?php endforeach; ?></select></label>
                                <label class="station-field span-2"><span>Supplier</span><select name="supplier_name" data-global-prepaid-supplier>
                                    <option value="">Select supplier</option>
                                    <?php foreach ($serviceSupplierOptions as $supplierOption): ?>
                                        <?php $supplierOptionName = trim((string) ($supplierOption['name'] ?? '')); ?>
                                        <?php if ($supplierOptionName !== ''): ?>
                                            <option value="<?= e($supplierOptionName) ?>"><?= e($supplierOptionName) ?></option>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                    <option value="__add_supplier__">+ Add new supplier...</option>
                                </select></label>
                                <label class="station-field span-2"><span>Currency</span><select name="advance_currency" data-global-prepaid-currency><?php foreach (['PKR', 'AED', 'USD'] as $currencyOption): ?><option value="<?= e($currencyOption) ?>"><?= e($currencyOption) ?></option><?php endforeach; ?></select></label>
                                <label class="station-field span-2"><span>Payment Date</span><input type="date" name="advance_date" value="<?= e(date('Y-m-d')) ?>"></label>
                                <label class="station-field span-2"><span>Advance Amount</span><input type="number" step="0.01" name="advance_amount" value="0.00" data-global-prepaid-amount></label>
                                <label class="station-field span-3"><span>Reference</span><input type="text" name="advance_reference_number" value=""></label>
                                <label class="station-field span-3"><span>Remarks</span><input type="text" name="advance_remarks" value=""></label>
                            </div>
                            <div class="workspace-feedback workspace-feedback--inline" data-global-prepaid-supplier-feedback hidden></div>
                            <div class="station-command-buttons top-gap">
                                <button class="btn btn-primary btn-sm" type="submit" data-global-prepaid-supplier-submit>Save Prepaid Supplier Payment</button>
                                <a class="btn btn-sm" href="<?= e(url('/reports?report=supplier_prepaid_payments')) ?>" target="_blank" rel="noopener">View All Prepaid Payments</a>
                            </div>
                        </form>
                    </article>
                </div>
            </div>
        </div>
    </section>

    <section class="customer-picker-modal customer-picker-modal--child" data-new-customer-modal hidden aria-hidden="true">
        <div class="customer-picker-modal__backdrop" data-new-customer-close onclick="return window.workspaceCloseStandaloneEditCustomerModal && window.workspaceCloseStandaloneEditCustomerModal(event)"></div>
        <div class="customer-picker-modal__dialog new-customer-modal" role="dialog" aria-modal="true" aria-labelledby="new-customer-title">
            <header class="customer-picker-modal__header new-customer-modal__header">
                <div>
                    <strong id="new-customer-title" data-new-customer-title>New Customer</strong>
                    <span>Create the customer profile first, then continue booking with it.</span>
                </div>
                <button class="btn btn-sm" type="button" data-new-customer-close onclick="return window.workspaceCloseStandaloneEditCustomerModal && window.workspaceCloseStandaloneEditCustomerModal(event)">Close</button>
            </header>
            <div class="customer-picker-modal__body new-customer-modal__body">
                <form method="post" action="<?= e(url('/workspace/travelers/save')) ?>" class="new-customer-form" data-new-customer-form>
                    <?= \App\Helpers\Csrf::input() ?>
                    <input type="hidden" name="booking_id" value="0">
                    <input type="hidden" name="traveler_id" value="0" data-new-customer-traveler-id>
                    <input type="hidden" name="traveler_role" value="lead">
                    <div class="station-form-grid station-form-grid--6 station-form-grid--inline new-customer-grid">
                        <label class="station-field span-2"><span>Branch</span><select name="traveler_branch_id"><?php foreach ($branchOptions as $branchRow): ?><option value="<?= e((string) $branchRow['id']) ?>" <?= (int) $branchRow['id'] === (int) $workspaceBooking['branchId'] ? 'selected' : '' ?>><?= e((string) $branchRow['name']) ?></option><?php endforeach; ?></select></label>
                        <label class="station-field span-2"><span>First Name</span><input type="text" name="first_name" value="" data-new-customer-focus></label>
                        <label class="station-field span-2"><span>Family Name</span><input type="text" name="last_name" value=""></label>
                        <label class="station-field span-2"><span>Family ID</span><input type="text" name="family_id" value=""></label>
                        <label class="station-field span-2"><span>Gender</span><select name="gender"><option value="male">Male</option><option value="female">Female</option><option value="other">Other</option><option value="unspecified" selected>Unspecified</option></select></label>
                        <label class="station-field span-2"><span>Date of Birth</span><input type="date" name="date_of_birth" value=""></label>
                        <label class="station-field span-2"><span>Passport No.</span><input type="text" name="passport_number" value=""></label>
                        <label class="station-field span-2"><span>Passport Expiry</span><input type="date" name="passport_expiry" value=""></label>
                        <label class="station-field span-3"><span>Phone</span><input type="text" name="mobile" value=""></label>
                        <label class="station-field span-3"><span>Occupation</span><input type="text" name="occupation" value=""></label>
                        <label class="station-field span-3"><span>Current Residence</span><input type="text" name="current_residence" value=""></label>
                        <label class="station-field span-3"><span>Permanent Residence</span><input type="text" name="permanent_residence" value=""></label>
                        <label class="station-field span-2"><span>Village</span><input type="text" name="village" value=""></label>
                        <label class="station-field span-2"><span>District</span><input type="text" name="district" value=""></label>
                        <label class="station-field span-2"><span>Color Tag</span><select name="color_tag"><option value="none" selected>None</option><option value="green">Green</option><option value="blue">Blue</option><option value="orange">Orange</option><option value="red">Red</option><option value="gold">Gold</option><option value="purple">Purple</option></select></label>
                        <label class="station-field span-3"><span>Nationality</span><input type="text" name="nationality" value=""></label>
                        <input type="hidden" name="address" value="">
                        <label class="station-field station-field--top span-6"><span>Description</span><textarea rows="4" name="notes"></textarea></label>
                    </div>
                    <div class="station-command-buttons top-gap new-customer-actions">
                        <button class="btn btn-primary btn-sm" type="submit" data-new-customer-submit>Save Customer</button>
                        <button class="btn btn-sm" type="button" data-offline-action="save-customer" hidden>Save Customer Offline</button>
                        <button class="btn btn-sm" type="button" data-new-customer-close onclick="return window.workspaceCloseStandaloneEditCustomerModal && window.workspaceCloseStandaloneEditCustomerModal(event)">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </section>

    <section class="customer-picker-modal customer-finder-modal" data-customer-picker hidden aria-hidden="true">
        <div class="customer-picker-modal__backdrop" data-customer-picker-close onclick="return window.workspaceCloseStandaloneCustomerModal && window.workspaceCloseStandaloneCustomerModal(event)"></div>
        <div class="customer-picker-modal__dialog customer-finder-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="customer-picker-title">
            <header class="customer-picker-modal__header customer-finder-modal__header">
                <div>
                    <strong id="customer-picker-title">Find Customer</strong>
                    <span>Search customer profiles</span>
                </div>
                <button class="btn btn-sm" type="button" data-customer-picker-close onclick="return window.workspaceCloseStandaloneCustomerModal && window.workspaceCloseStandaloneCustomerModal(event)">Close</button>
            </header>
            <div class="customer-picker-modal__body customer-finder-modal__body">
                <label class="station-field station-field--full customer-finder-modal__search">
                    <span>Search</span>
                    <input type="text" value="" placeholder="Name / family ID / passport / phone / village / district" data-customer-picker-input oninput="window.workspaceHandleStandaloneCustomerModalInput && window.workspaceHandleStandaloneCustomerModalInput(this)" onkeydown="window.workspaceHandleStandaloneCustomerModalKeydown && window.workspaceHandleStandaloneCustomerModalKeydown(event, this)">
                </label>
                <div class="dense-table-wrap customer-finder-modal__table">
                    <table class="dense-table customer-picker-table">
                        <thead><tr><th>Name</th><th>Family ID</th><th>Passport</th><th>Phone</th><th>Location</th><th>Color</th><th>Action</th></tr></thead>
                        <tbody data-customer-picker-results></tbody>
                    </table>
                </div>
            </div>
        </div>
    </section>

    <script id="workspace-service-lines-data" type="application/json"><?= $serviceLinesJson ?></script>
    <script id="workspace-travelers-data" type="application/json"><?= $travelersJson ?></script>
    <script id="workspace-customer-directory-data" type="application/json"><?= $customerDirectoryJson ?></script>
    <script id="workspace-customer-open-receivables-data" type="application/json"><?= $customerOpenReceivablesJson ?></script>
    <script id="workspace-daily-settlement-rates-data" type="application/json"><?= $dailySettlementRatesJson ?></script>
    <script id="workspace-payment-receipts-data" type="application/json"><?= $paymentReceiptsJson ?></script>
    <script id="workspace-payment-allocations-data" type="application/json"><?= $paymentAllocationsJson ?></script>
    <script id="workspace-payment-treasury-accounts-data" type="application/json"><?= $paymentTreasuryAccountsJson ?></script>
    <script id="workspace-service-suppliers-data" type="application/json"><?= $serviceSupplierOptionsJson ?></script>
    <script id="workspace-business-sources-data" type="application/json"><?= $businessSourceOptionsJson ?></script>
    <script id="workspace-branch-options-data" type="application/json"><?= json_encode(array_map(static fn (array $branch): array => [
        'id' => (int) ($branch['id'] ?? 0),
        'name' => (string) ($branch['name'] ?? ''),
        'baseCurrency' => strtoupper(trim((string) ($branch['base_currency'] ?? 'PKR'))) ?: 'PKR',
    ], $branchOptions), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?: '[]' ?></script>
    <script>
        (function () {
            var init = function () {
            var station = document.querySelector('[data-workspace-station]');
            var field = document.getElementById('lead-traveler-focus');
            var anchor = station ? station.querySelector('[data-customer-inline-search]') : null;
            var panel = station ? station.querySelector('[data-customer-autocomplete-panel]') : null;
            var results = station ? station.querySelector('[data-customer-autocomplete-results]') : null;
            var dataNode = document.getElementById('workspace-customer-directory-data');
            var selectedCustomerId = station ? station.querySelector('[data-booking-selected-customer-id]') : null;
            var bookingMobileField = station ? station.querySelector('[data-booking-mobile-field]') : null;
            var bookingPassportField = station ? station.querySelector('[data-booking-passport-field]') : null;
            var summaryMobile = station ? station.querySelector('[data-customer-summary-mobile]') : null;
            var summaryFamily = station ? station.querySelector('[data-customer-summary-family]') : null;
            var summaryColor = station ? station.querySelector('[data-customer-summary-color]') : null;
            var servicePassengerField = station ? station.querySelector('[data-service-passenger-name]') : null;
            var serviceTravelerIdField = station ? station.querySelector('[data-service-field="travelerId"]') : null;
            var serviceIdField = station ? station.querySelector('[data-service-field="serviceId"]') : null;
            var travelerIdField = station ? station.querySelector('[data-traveler-field="travelerId"]') : null;
            var travelerNoField = station ? station.querySelector('[data-traveler-field="travelerNo"]') : null;
            var travelerTypeField = station ? station.querySelector('[data-traveler-field="type"]') : null;
            var travelerStatusField = station ? station.querySelector('[data-traveler-field="status"]') : null;
            var travelerRoleField = station ? station.querySelector('[data-traveler-field="travelerRole"]') : null;
            var travelerBranchIdField = station ? station.querySelector('[data-traveler-field="branchId"]') : null;
            var travelerFullNameField = station ? station.querySelector('[data-traveler-field="fullName"]') : null;
            var travelerGenderField = station ? station.querySelector('[data-traveler-field="gender"]') : null;
            var travelerDateOfBirthField = station ? station.querySelector('[data-traveler-field="dateOfBirth"]') : null;
            var travelerPassportNoField = station ? station.querySelector('[data-traveler-field="passportNo"]') : null;
            var travelerPassportExpiryField = station ? station.querySelector('[data-traveler-field="passportExpiry"]') : null;
            var travelerMobileField = station ? station.querySelector('[data-traveler-field="mobile"]') : null;
            var travelerAddressField = station ? station.querySelector('[data-traveler-field="address"]') : null;
            var travelerNationalityField = station ? station.querySelector('[data-traveler-field="nationality"]') : null;
            var travelerNotesField = station ? station.querySelector('[data-traveler-field="notes"]') : null;
            var activeTravelerReference = station ? station.querySelector('[data-active-traveler-reference]') : null;
            var pickerData = [];
            var filtered = [];
            var activeIndex = 0;

            if (!station || !field || !anchor || !panel || !results || !dataNode) {
                return;
            }

            try {
                pickerData = JSON.parse(dataNode.textContent || '[]');
            } catch (error) {
                pickerData = [];
            }

            if (!panel.classList.contains('customer-inline-picker--overlay')) {
                panel.classList.add('customer-inline-picker--overlay');
                document.body.appendChild(panel);
            }

            var normalize = function (value) {
                return String(value || '').trim().toLowerCase();
            };

            var colorLabel = function (value) {
                return String(value || '').replace(/_/g, ' ').replace(/\b\w/g, function (m) { return m.toUpperCase(); });
            };

            var fillValue = function (fieldNode, value) {
                if (!fieldNode) {
                    return;
                }

                fieldNode.value = value == null ? '' : String(value);
            };

            var customerLabel = function (customer) {
                return [
                    customer.first_name,
                    customer.last_name,
                    customer.full_name,
                    customer.family_id,
                    customer.passport_number,
                    customer.mobile,
                    customer.village,
                    customer.district,
                    customer.current_residence,
                    customer.permanent_residence,
                    customer.address,
                    customer.occupation,
                    customer.notes,
                    customer.nationality
                ].map(normalize).join(' ');
            };

            var positionPanel = function () {
                if (panel.hidden) {
                    return;
                }

                var rect = anchor.getBoundingClientRect();
                var viewportWidth = window.innerWidth;
                var viewportHeight = window.innerHeight;
                var horizontalPadding = 12;
                var verticalGap = 6;
                var panelWidth = Math.min(Math.max(rect.width, 720), viewportWidth - (horizontalPadding * 2));
                var left = Math.max(horizontalPadding, Math.min(rect.left, viewportWidth - panelWidth - horizontalPadding));

                panel.style.width = panelWidth + 'px';
                panel.style.maxWidth = (viewportWidth - (horizontalPadding * 2)) + 'px';
                panel.style.left = left + 'px';

                var panelHeight = panel.offsetHeight || 320;
                var spaceBelow = viewportHeight - rect.bottom - verticalGap - horizontalPadding;
                var spaceAbove = rect.top - verticalGap - horizontalPadding;
                var openAbove = spaceBelow < Math.min(panelHeight, 260) && spaceAbove > spaceBelow;
                var top = openAbove
                    ? Math.max(horizontalPadding, rect.top - panelHeight - verticalGap)
                    : Math.min(rect.bottom + verticalGap, viewportHeight - panelHeight - horizontalPadding);

                panel.style.top = Math.max(horizontalPadding, top) + 'px';
            };

            var hidePanel = function () {
                panel.hidden = true;
            };

            var applyCustomer = function (customer) {
                if (!customer) {
                    return;
                }

                station.dataset.hasSelectedCustomer = '1';
                if (selectedCustomerId) {
                    selectedCustomerId.value = String(customer.id || '');
                }
                field.value = customer.full_name || '';
                if (bookingMobileField) {
                    bookingMobileField.value = customer.mobile || '';
                }
                if (bookingPassportField) {
                    bookingPassportField.value = customer.passport_number || '';
                }
                if (summaryMobile) {
                    summaryMobile.value = customer.mobile || '';
                }
                if (summaryFamily) {
                    summaryFamily.value = customer.family_id || '-';
                }
                if (summaryColor) {
                    summaryColor.value = customer.color_tag ? colorLabel(customer.color_tag) : '-';
                }
                if (travelerIdField) {
                    fillValue(travelerIdField, customer.id || '');
                }
                if (travelerNoField) {
                    fillValue(travelerNoField, customer.id ? 'TRV-' + String(customer.id).padStart(3, '0') : 'TRV-DRAFT');
                }
                if (travelerTypeField) {
                    fillValue(travelerTypeField, 'Lead');
                }
                if (travelerStatusField) {
                    fillValue(travelerStatusField, 'Selected Customer Profile');
                }
                if (travelerRoleField) {
                    fillValue(travelerRoleField, 'lead');
                }
                if (travelerBranchIdField) {
                    fillValue(travelerBranchIdField, customer.branch_id || '');
                }
                if (travelerFullNameField) {
                    fillValue(travelerFullNameField, customer.full_name || '');
                }
                if (travelerGenderField) {
                    fillValue(travelerGenderField, customer.gender || 'unspecified');
                }
                if (travelerDateOfBirthField) {
                    fillValue(travelerDateOfBirthField, customer.date_of_birth || '');
                }
                if (travelerPassportNoField) {
                    fillValue(travelerPassportNoField, customer.passport_number || '');
                }
                if (travelerPassportExpiryField) {
                    fillValue(travelerPassportExpiryField, customer.passport_expiry || '');
                }
                if (travelerMobileField) {
                    fillValue(travelerMobileField, customer.mobile || '');
                }
                if (travelerAddressField) {
                    fillValue(travelerAddressField, customer.current_residence || customer.address || '');
                }
                if (travelerNationalityField) {
                    fillValue(travelerNationalityField, customer.nationality || '');
                }
                if (travelerNotesField) {
                    fillValue(travelerNotesField, customer.notes || '');
                }
                if (activeTravelerReference) {
                    activeTravelerReference.textContent = customer.id ? 'TRV-' + String(customer.id).padStart(3, '0') : 'TRV-DRAFT';
                }
                if (servicePassengerField && serviceTravelerIdField) {
                    var currentServiceId = serviceIdField ? parseInt(String(serviceIdField.value || '0'), 10) : 0;
                    var currentTravelerId = parseInt(String(serviceTravelerIdField.value || '0'), 10);
                    var currentPassengerName = String(servicePassengerField.value || '').trim().toLowerCase();
                    var selectedCustomerName = String(customer.full_name || '').trim().toLowerCase();
                    var shouldDefaultPassenger = currentServiceId <= 0 && (
                        currentPassengerName === ''
                        || currentTravelerId <= 0
                        || currentPassengerName === selectedCustomerName
                    );
                    if (shouldDefaultPassenger) {
                        fillValue(serviceTravelerIdField, customer.id || '');
                        fillValue(servicePassengerField, customer.full_name || '');
                    }
                }

                field.dispatchEvent(new Event('change', { bubbles: true }));
                hidePanel();
                station.dispatchEvent(new CustomEvent('workspace:customer-selected', { bubbles: true }));
            };

            var renderPanel = function () {
                results.innerHTML = '';

                if (!filtered.length) {
                    var empty = document.createElement('div');
                    empty.className = 'customer-inline-picker__empty';
                    empty.textContent = 'No customer matches this search.';
                    results.appendChild(empty);
                    panel.hidden = false;
                    positionPanel();
                    return;
                }

                filtered.forEach(function (customer, index) {
                    var row = document.createElement('button');
                    row.type = 'button';
                    row.className = 'customer-inline-picker__row' + (index === activeIndex ? ' is-active' : '');
                    row.innerHTML =
                        '<span class=\"customer-inline-picker__cell customer-inline-picker__cell--name\">' + (customer.full_name || '') + '</span>' +
                        '<span class=\"customer-inline-picker__cell customer-inline-picker__cell--id\">' + (customer.family_id || (customer.id ? '#' + customer.id : '-')) + '</span>' +
                        '<span class=\"customer-inline-picker__cell customer-inline-picker__cell--passport\">' + (customer.passport_number || '-') + '</span>' +
                        '<span class=\"customer-inline-picker__cell customer-inline-picker__cell--phone\">' + (customer.mobile || '-') + '</span>' +
                        '<span class=\"customer-inline-picker__cell customer-inline-picker__cell--address\">' + (customer.village || customer.district || customer.current_residence || customer.address || customer.notes || '-') + '</span>' +
                        '<span class=\"customer-inline-picker__cell customer-inline-picker__cell--address\">' + (customer.color_tag ? colorLabel(customer.color_tag) : '-') + '</span>';
                    row.addEventListener('mousedown', function (event) {
                        event.preventDefault();
                        applyCustomer(customer);
                    });
                    results.appendChild(row);
                });

                panel.hidden = false;
                positionPanel();
            };

            var openPanel = function () {
                var query = normalize(field.value);
                filtered = query === ''
                    ? pickerData.slice(0, 12)
                    : pickerData.filter(function (customer) { return customerLabel(customer).indexOf(query) !== -1; }).slice(0, 12);
                activeIndex = 0;
                renderPanel();
            };

            window.workspaceOpenInlineCustomerLookup = function () {
                openPanel();
            };

            window.workspaceHandleInlineCustomerLookupInput = function () {
                station.dataset.hasSelectedCustomer = '0';
                if (selectedCustomerId) {
                    selectedCustomerId.value = '';
                }
                openPanel();
            };

            window.workspaceHandleInlineCustomerLookupKeydown = function (event) {
                if (event.key === 'ArrowDown') {
                    event.preventDefault();
                    activeIndex = Math.min(activeIndex + 1, Math.max(filtered.length - 1, 0));
                    renderPanel();
                    return;
                }

                if (event.key === 'ArrowUp') {
                    event.preventDefault();
                    activeIndex = Math.max(activeIndex - 1, 0);
                    renderPanel();
                    return;
                }

                if (event.key === 'Enter') {
                    if (!panel.hidden) {
                        event.preventDefault();
                        applyCustomer(filtered[activeIndex] || null);
                    }
                    return;
                }

                if (event.key === 'Escape') {
                    hidePanel();
                }
            };

            document.addEventListener('click', function (event) {
                if (!anchor.contains(event.target) && !panel.contains(event.target)) {
                    hidePanel();
                }
            });

            window.addEventListener('resize', positionPanel);
            document.addEventListener('scroll', positionPanel, true);

            var focusMode = new URLSearchParams(window.location.search).get('focus');
            if (focusMode === 'customer') {
                window.setTimeout(function () {
                    field.focus();
                    field.select();
                    openPanel();
                }, 120);
            }
            };

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', init, { once: true });
                return;
            }

            init();
        })();
    </script>
    <script>
        (function () {
            var initStandaloneCustomerModal = function () {
                var station = document.querySelector('[data-workspace-station]');
                var findButton = station ? station.querySelector('[data-workspace-action="add-traveler"]') : null;
                var newCustomerButton = station ? station.querySelector('[data-workspace-action="new-customer"]') : null;
                var modal = station ? station.querySelector('[data-customer-picker]') : null;
                var closeButtons = station ? station.querySelectorAll('[data-customer-picker-close]') : [];
                var searchInput = station ? station.querySelector('[data-customer-picker-input]') : null;
                var resultsBody = station ? station.querySelector('[data-customer-picker-results]') : null;
                var field = document.getElementById('lead-traveler-focus');
                var selectedCustomerId = station ? station.querySelector('[data-booking-selected-customer-id]') : null;
                var bookingMobileField = station ? station.querySelector('[data-booking-mobile-field]') : null;
                var bookingPassportField = station ? station.querySelector('[data-booking-passport-field]') : null;
                var summaryMobile = station ? station.querySelector('[data-customer-summary-mobile]') : null;
                var summaryFamily = station ? station.querySelector('[data-customer-summary-family]') : null;
                var summaryColor = station ? station.querySelector('[data-customer-summary-color]') : null;
                var newCustomerModal = station ? station.querySelector('[data-new-customer-modal]') : null;
                var newCustomerForm = station ? station.querySelector('[data-new-customer-form]') : null;
                var newCustomerTravelerId = station ? station.querySelector('[data-new-customer-traveler-id]') : null;
                var newCustomerTitle = station ? station.querySelector('[data-new-customer-title]') : null;
                var newCustomerSubmit = station ? station.querySelector('[data-new-customer-submit]') : null;
                var newCustomerFocusField = station ? station.querySelector('[data-new-customer-focus]') : null;
                var dataNode = document.getElementById('workspace-customer-directory-data');
                var customers = [];
                var filtered = [];
                var activeIndex = 0;

                if (!station || !findButton || !newCustomerButton || !modal || !searchInput || !resultsBody || !dataNode) {
                    return;
                }

                if (findButton.dataset.standaloneCustomerModalInit === '1') {
                    return;
                }
                findButton.dataset.standaloneCustomerModalInit = '1';
                newCustomerButton.dataset.standaloneCustomerModalInit = '1';

                try {
                    customers = JSON.parse(dataNode.textContent || '[]');
                } catch (error) {
                    customers = [];
                }

                var normalize = function (value) {
                    return String(value || '').trim().toLowerCase();
                };

                var colorLabel = function (value) {
                    return String(value || '').replace(/_/g, ' ').replace(/\b\w/g, function (m) { return m.toUpperCase(); });
                };

                var customerLabel = function (customer) {
                    return [
                        customer.first_name,
                        customer.last_name,
                        customer.full_name,
                        customer.family_id,
                        customer.passport_number,
                        customer.mobile,
                        customer.village,
                        customer.district,
                        customer.current_residence,
                        customer.permanent_residence,
                        customer.address,
                        customer.occupation,
                        customer.notes,
                        customer.nationality
                    ].map(normalize).join(' ');
                };

                var applyCustomer = function (customer) {
                    if (!customer) {
                        return;
                    }

                    if (typeof window.workspaceLoadCustomerIntoFreshInvoice === 'function') {
                        window.workspaceLoadCustomerIntoFreshInvoice(customer);
                        closeModal();
                        return;
                    }

                    station.dataset.hasSelectedCustomer = '1';
                    if (selectedCustomerId) {
                        selectedCustomerId.value = String(customer.id || '');
                    }
                    if (field) {
                        field.value = customer.full_name || '';
                        field.dispatchEvent(new Event('change', { bubbles: true }));
                    }
                    if (bookingMobileField) {
                        bookingMobileField.value = customer.mobile || '';
                    }
                    if (bookingPassportField) {
                        bookingPassportField.value = customer.passport_number || '';
                    }
                    if (summaryMobile) {
                        summaryMobile.value = customer.mobile || '';
                    }
                    if (summaryFamily) {
                        summaryFamily.value = customer.family_id || '-';
                    }
                    if (summaryColor) {
                        summaryColor.value = customer.color_tag ? colorLabel(customer.color_tag) : '-';
                    }

                    closeModal();
                    station.dispatchEvent(new CustomEvent('workspace:customer-selected', { bubbles: true }));
                };

                var openEditModal = function (customer) {
                    if (!customer || !newCustomerModal || !(newCustomerForm instanceof HTMLFormElement)) {
                        return;
                    }

                    newCustomerForm.reset();
                    if (newCustomerTravelerId) {
                        newCustomerTravelerId.value = String(customer.id || 0);
                    }
                    if (newCustomerTitle) {
                        newCustomerTitle.textContent = 'Edit Customer';
                    }
                    if (newCustomerSubmit) {
                        newCustomerSubmit.textContent = 'Update Customer';
                    }

                    var fill = function (name, value) {
                        var formField = newCustomerForm.elements.namedItem(name);
                        if (formField instanceof HTMLInputElement || formField instanceof HTMLTextAreaElement || formField instanceof HTMLSelectElement) {
                            formField.value = value == null ? '' : value;
                        }
                    };

                    fill('traveler_branch_id', customer.branch_id || '');
                    fill('first_name', customer.first_name || '');
                    fill('last_name', customer.last_name || '');
                    fill('gender', customer.gender || 'unspecified');
                    fill('date_of_birth', customer.date_of_birth || '');
                    fill('passport_number', customer.passport_number || '');
                    fill('passport_expiry', customer.passport_expiry || '');
                    fill('mobile', customer.mobile || '');
                    fill('occupation', customer.occupation || '');
                    fill('current_residence', customer.current_residence || customer.address || '');
                    fill('permanent_residence', customer.permanent_residence || customer.address || '');
                    fill('village', customer.village || '');
                    fill('district', customer.district || '');
                    fill('family_id', customer.family_id || '');
                    fill('color_tag', customer.color_tag || 'none');
                    fill('address', customer.current_residence || customer.address || '');
                    fill('nationality', customer.nationality || '');
                    fill('notes', customer.notes || '');

                    newCustomerModal.hidden = false;
                    newCustomerModal.setAttribute('aria-hidden', 'false');
                    window.setTimeout(function () {
                        if (newCustomerFocusField) {
                            newCustomerFocusField.focus();
                            if (typeof newCustomerFocusField.select === 'function') {
                                newCustomerFocusField.select();
                            }
                        }
                    }, 60);
                };

                var openNewCustomerModal = function () {
                    if (!newCustomerModal || !(newCustomerForm instanceof HTMLFormElement)) {
                        return;
                    }

                    newCustomerForm.reset();
                    if (newCustomerTravelerId) {
                        newCustomerTravelerId.value = '0';
                    }
                    if (newCustomerTitle) {
                        newCustomerTitle.textContent = 'New Customer';
                    }
                    if (newCustomerSubmit) {
                        newCustomerSubmit.textContent = 'Save Customer';
                    }

                    var branchField = newCustomerForm.elements.namedItem('traveler_branch_id');
                    if (branchField instanceof HTMLSelectElement) {
                        branchField.value = '<?= e((string) $workspaceBooking['branchId']) ?>';
                    }

                    newCustomerModal.hidden = false;
                    newCustomerModal.setAttribute('aria-hidden', 'false');
                    window.setTimeout(function () {
                        if (newCustomerFocusField) {
                            newCustomerFocusField.focus();
                            if (typeof newCustomerFocusField.select === 'function') {
                                newCustomerFocusField.select();
                            }
                        }
                    }, 60);
                };

                var closeEditModal = function () {
                    if (!newCustomerModal) {
                        return;
                    }

                    newCustomerModal.hidden = true;
                    newCustomerModal.setAttribute('aria-hidden', 'true');
                    window.setTimeout(function () {
                        if (searchInput && !modal.hidden) {
                            searchInput.focus();
                            searchInput.select();
                        }
                    }, 40);
                };

                var renderRows = function () {
                    resultsBody.innerHTML = '';

                    if (!filtered.length) {
                        var emptyRow = document.createElement('tr');
                        emptyRow.innerHTML = '<td colspan="7" class="empty-cell">No customer matches this search.</td>';
                        resultsBody.appendChild(emptyRow);
                        return;
                    }

                    filtered.forEach(function (customer, index) {
                        var row = document.createElement('tr');
                        row.tabIndex = 0;
                        row.className = index === activeIndex ? 'is-active' : '';
                        row.innerHTML =
                            '<td>' + (customer.full_name || '') + '</td>' +
                            '<td>' + (customer.family_id || '') + '</td>' +
                            '<td>' + (customer.passport_number || '') + '</td>' +
                            '<td>' + (customer.mobile || '') + '</td>' +
                            '<td>' + (customer.village || customer.district || customer.current_residence || customer.address || '') + '</td>' +
                            '<td>' + (customer.color_tag ? colorLabel(customer.color_tag) : '') + '</td>' +
                            '<td><button class="btn btn-sm" type="button">Edit</button></td>';

                        row.addEventListener('click', function () {
                            applyCustomer(customer);
                        });
                        row.addEventListener('dblclick', function () {
                            applyCustomer(customer);
                        });
                        row.addEventListener('keydown', function (event) {
                            if (event.key === 'Enter') {
                                event.preventDefault();
                                applyCustomer(customer);
                            }
                        });

                        var editButton = row.querySelector('button');
                        if (editButton) {
                            editButton.addEventListener('click', function (event) {
                                event.preventDefault();
                                event.stopPropagation();
                                openEditModal(customer);
                            });
                        }

                        resultsBody.appendChild(row);
                    });
                };

                var filterRows = function () {
                    var query = normalize(searchInput.value);
                    filtered = query === '' ? customers.slice() : customers.filter(function (customer) {
                        return customerLabel(customer).indexOf(query) !== -1;
                    });
                    activeIndex = 0;
                    renderRows();
                };

                function closeModal() {
                    modal.hidden = true;
                    modal.setAttribute('aria-hidden', 'true');
                    if (searchInput) {
                        searchInput.value = '';
                    }
                }

                function openModal() {
                    modal.hidden = false;
                    modal.setAttribute('aria-hidden', 'false');
                    filterRows();
                    window.setTimeout(function () {
                        searchInput.focus();
                        searchInput.select();
                    }, 60);
                }

                window.workspaceOpenStandaloneCustomerModal = function (event) {
                    if (event && typeof event.preventDefault === 'function') {
                        event.preventDefault();
                    }
                    if (event && typeof event.stopImmediatePropagation === 'function') {
                        event.stopImmediatePropagation();
                    } else if (event && typeof event.stopPropagation === 'function') {
                        event.stopPropagation();
                    }

                    if (typeof window.workspaceStartFreshInvoiceForCustomerAction === 'function'
                        && window.workspaceStartFreshInvoiceForCustomerAction('find-customer')) {
                        return false;
                    }

                    openModal();
                    return false;
                };

                window.workspaceOpenStandaloneNewCustomerModal = function (event) {
                    if (event && typeof event.preventDefault === 'function') {
                        event.preventDefault();
                    }
                    if (event && typeof event.stopImmediatePropagation === 'function') {
                        event.stopImmediatePropagation();
                    } else if (event && typeof event.stopPropagation === 'function') {
                        event.stopPropagation();
                    }

                    if (typeof window.workspaceStartFreshInvoiceForCustomerAction === 'function'
                        && window.workspaceStartFreshInvoiceForCustomerAction('new-customer')) {
                        return false;
                    }

                    openNewCustomerModal();
                    return false;
                };

                window.workspaceCloseStandaloneCustomerModal = function (event) {
                    if (event && typeof event.preventDefault === 'function') {
                        event.preventDefault();
                    }
                    if (event && typeof event.stopImmediatePropagation === 'function') {
                        event.stopImmediatePropagation();
                    } else if (event && typeof event.stopPropagation === 'function') {
                        event.stopPropagation();
                    }

                    closeModal();
                    return false;
                };

                window.workspaceCloseStandaloneEditCustomerModal = function (event) {
                    if (event && typeof event.preventDefault === 'function') {
                        event.preventDefault();
                    }
                    if (event && typeof event.stopImmediatePropagation === 'function') {
                        event.stopImmediatePropagation();
                    } else if (event && typeof event.stopPropagation === 'function') {
                        event.stopPropagation();
                    }

                    closeEditModal();
                    return false;
                };

                window.workspaceHandleStandaloneCustomerModalInput = function () {
                    filterRows();
                };

                window.workspaceHandleStandaloneCustomerModalKeydown = function (event) {
                    if (!event) {
                        return false;
                    }

                    if (event.key === 'ArrowDown') {
                        event.preventDefault();
                        activeIndex = Math.min(activeIndex + 1, Math.max(filtered.length - 1, 0));
                        renderRows();
                        return false;
                    }

                    if (event.key === 'ArrowUp') {
                        event.preventDefault();
                        activeIndex = Math.max(activeIndex - 1, 0);
                        renderRows();
                        return false;
                    }

                    if (event.key === 'Enter') {
                        event.preventDefault();
                        applyCustomer(filtered[activeIndex] || null);
                        return false;
                    }

                    if (event.key === 'Escape') {
                        event.preventDefault();
                        if (newCustomerModal && !newCustomerModal.hidden) {
                            closeEditModal();
                            return false;
                        }
                        closeModal();
                        return false;
                    }

                    return true;
                };

                findButton.addEventListener('click', function (event) {
                    event.preventDefault();
                    event.stopImmediatePropagation();
                    if (typeof window.workspaceStartFreshInvoiceForCustomerAction === 'function'
                        && window.workspaceStartFreshInvoiceForCustomerAction('find-customer')) {
                        return;
                    }
                    openModal();
                }, true);

                newCustomerButton.addEventListener('click', function (event) {
                    event.preventDefault();
                    event.stopImmediatePropagation();
                    if (typeof window.workspaceStartFreshInvoiceForCustomerAction === 'function'
                        && window.workspaceStartFreshInvoiceForCustomerAction('new-customer')) {
                        return;
                    }
                    openNewCustomerModal();
                }, true);

                Array.prototype.forEach.call(closeButtons, function (button) {
                    button.addEventListener('click', function (event) {
                        event.preventDefault();
                        closeModal();
                    });
                });

                searchInput.addEventListener('input', filterRows);
                searchInput.addEventListener('keydown', function (event) {
                    window.workspaceHandleStandaloneCustomerModalKeydown(event);
                });
            };

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', initStandaloneCustomerModal, { once: true });
                return;
            }

            initStandaloneCustomerModal();
        })();
    </script>
    <script>
    (function () {
        var initEnterAsTab = function () {
            var station = document.querySelector('[data-workspace-station]');

            if (!station || station.dataset.enterAsTabInit === '1') {
                return;
            }

            station.dataset.enterAsTabInit = '1';

            var shouldSkipField = function (field, options) {
                options = options || {};
                if (!field) {
                    return true;
                }

                var tag = field.tagName ? field.tagName.toLowerCase() : '';
                var type = field.type ? field.type.toLowerCase() : '';

                if (field.disabled || field.readOnly || field.hidden) {
                    return true;
                }

                if (field.closest('[hidden]') || field.closest('[aria-hidden="true"]')) {
                    return true;
                }

                if (tag === 'textarea' && !options.allowTextarea) {
                    return true;
                }

                if ((tag === 'button' || tag === 'a') && !options.allowActions) {
                    return true;
                }

                if (type === 'hidden' || type === 'button' || type === 'submit' || type === 'reset') {
                    return true;
                }

                var style = window.getComputedStyle(field);

                return style.display === 'none' || style.visibility === 'hidden';
            };

            var isVisibleAction = function (field) {
                return !shouldSkipField(field, { allowActions: true });
            };

            var getFocusableFields = function () {
                return Array.prototype.filter.call(
                    station.querySelectorAll('input, select, textarea'),
                    function (field) {
                        return !shouldSkipField(field);
                    }
                );
            };

            var focusNextField = function (currentField) {
                var fields = getFocusableFields();
                var currentIndex = fields.indexOf(currentField);

                if (currentIndex === -1) {
                    return;
                }

                var nextField = fields[currentIndex + 1] || fields[0];

                if (nextField) {
                    nextField.focus();

                    if (typeof nextField.select === 'function' && nextField.tagName.toLowerCase() === 'input') {
                        nextField.select();
                    }

                    if (nextField.type === 'date' && typeof nextField.showPicker === 'function') {
                        try {
                            nextField.showPicker();
                        } catch (error) {
                            // Browser may block automatic date picker. Focus still moves to date field.
                        }
                    }
                }
            };

            var findField = function (selector, options) {
                var field = station.querySelector(selector);
                if (!field) {
                    return null;
                }

                return shouldSkipField(field, options) ? null : field;
            };

            var scrollAndFocus = function (field) {
                if (!field) {
                    return;
                }

                var holder = field.closest('.legacy-financial-grid, .legacy-payment-strip, .legacy-service-band, .legacy-invoice-header, .legacy-payment-panel, .legacy-fin-block') || field;

                holder.scrollIntoView({
                    behavior: 'smooth',
                    block: 'center',
                    inline: 'nearest'
                });

                window.setTimeout(function () {
                    field.focus();

                    if (typeof field.select === 'function' && field.tagName.toLowerCase() === 'input') {
                        field.select();
                    }
                }, 90);
            };

            var workflowTargets = [
                ['[data-customer-autocomplete-input]', '.legacy-invoice-header__remarks input[name="remarks"]', { allowTextarea: true }],
                ['.legacy-invoice-header__remarks input[name="remarks"]', 'select[name="service_type"]'],
                ['select[name="service_type"]', 'input[name="ticket_number"]'],
                ['input[name="ticket_number"]', 'input[name="ticket_pnr"]'],
                ['input[name="ticket_pnr"]', '#legacy-service-form [name="supplier_name"]'],
                ['#legacy-service-form [name="supplier_name"]', 'select[name="ticket_type"]'],
                ['select[name="ticket_type"]', 'select[name="ticket_class"]'],
                ['select[name="ticket_class"]', 'input[name="ticket_departure_date"]'],
                ['input[name="ticket_departure_date"]', '[data-ticket-route-display]'],
                ['[data-ticket-route-display]', '#commercial-sale-price'],
                ['#commercial-sale-price', '#commercial-service-charge'],
                ['#commercial-service-charge', '[data-payment-focus="received_amount"]'],
                ['[data-payment-focus="received_amount"]', '[data-payment-submit-action="save"]', { allowActions: true }]
            ];

            var workflowNextFor = function (target) {
                if (!(target instanceof HTMLElement)) {
                    return null;
                }

                for (var index = 0; index < workflowTargets.length; index += 1) {
                    if (target.matches(workflowTargets[index][0])) {
                        return findField(workflowTargets[index][1], workflowTargets[index][2]);
                    }
                }

                return null;
            };

            var getSupplierField = function () {
                return station.querySelector('#legacy-service-form [name="supplier_name"]');
            };

            var supplierSelectionReady = function () {
                var supplierField = getSupplierField();
                if (!(supplierField instanceof HTMLSelectElement)) {
                    return true;
                }

                var value = String(supplierField.value || '').trim();
                return value !== '' && value !== '__add_supplier__';
            };

            var focusSupplierSelection = function () {
                var supplierField = getSupplierField();
                if (!(supplierField instanceof HTMLElement)) {
                    return;
                }

                if (typeof window.showFeedback === 'function') {
                    window.showFeedback('Select supplier first.');
                }

                if (
                    typeof window.workspaceFocusSupplierDropdown === 'function'
                    && window.workspaceFocusSupplierDropdown()
                ) {
                    return;
                }

                scrollAndFocus(supplierField);
            };

            station.addEventListener('workspace:customer-selected', function () {
                scrollAndFocus(findField('.legacy-invoice-header__remarks input[name="remarks"]', { allowTextarea: true }));
            });

            station.addEventListener('focusin', function (event) {
                var target = event.target;
                if (!(target instanceof HTMLElement)) {
                    return;
                }

                if (
                    (
                        target.matches('select[name="ticket_type"]')
                        || target.matches('select[name="ticket_class"]')
                        || target.matches('input[name="ticket_departure_date"]')
                        || target.matches('[data-ticket-route-display]')
                        || target.matches('#commercial-sale-price')
                        || target.matches('#commercial-service-charge')
                        || target.matches('[data-payment-focus="received_amount"]')
                    )
                    && !supplierSelectionReady()
                ) {
                    window.setTimeout(focusSupplierSelection, 0);
                }
            }, true);

            station.addEventListener('keydown', function (event) {
                if (event.key !== 'Enter') {
                    return;
                }

                var target = event.target;

                if (!target) {
                    return;
                }

                // Do not let the global Enter-as-Tab workflow steal Enter from supplier modals.
                if (
                    typeof target.closest === 'function' &&
                    target.closest('[data-service-supplier-add-form], [data-global-prepaid-supplier-form]')
                ) {
                    return;
                }
                if (
                    typeof target.closest === 'function' &&
                    target.closest('[data-payment-exchange-modal], [data-payment-detail-modal]')
                ) {
                    return;
                }
                // Do not interfere with customer/supplier finder Enter behavior.
                if (
                    target.matches('[data-customer-autocomplete-input]') ||
                    target.matches('[data-customer-picker-input]') ||
                    target.matches('[data-customer-dues-search]') ||
                    target.matches('[data-supplier-history-search]')
                ) {
                    if (target.matches('[data-customer-autocomplete-input]')) {
                        var panel = station.querySelector('[data-customer-autocomplete-panel]');
                        if (panel && panel.hidden) {
                            event.preventDefault();
                            event.stopPropagation();
                            scrollAndFocus(findField('.legacy-invoice-header__remarks input[name="remarks"]', { allowTextarea: true }));
                        }
                    }
                    return;
                }
                if (
                    target.matches('#workspace-search')
                    || target.matches('[data-workspace-search-submit]')
                ) {
                    return;
                }
                if (
                    target.matches('#commercial-sale-price')
                    && Number.parseInt(String(station.getAttribute('data-workspace-opened-existing-booking') || '0'), 10) !== 1
                    && String(target.value || '').trim() === ''
                ) {
                    event.preventDefault();
                    event.stopPropagation();
                    target.focus();
                    return;
                }
                if (
                    target.matches('select[name="payment_method"]')
                    || target.matches('[data-payment-treasury-select]')
                ) {
                    return;
                }

                if (
                    target.matches('#legacy-service-form [name="supplier_name"]')
                    && !supplierSelectionReady()
                ) {
                    event.preventDefault();
                    event.stopPropagation();
                    focusSupplierSelection();
                    return;
                }

                if (target.matches('[data-payment-submit-action="save"]')) {
                    event.preventDefault();
                    event.stopPropagation();
                    target.click();
                    return;
                }

                if (target.matches('[data-payment-action="print-receipt"]')) {
                    event.preventDefault();
                    event.stopPropagation();
                    target.click();
                    return;
                }

                if (shouldSkipField(target, { allowTextarea: true, allowActions: true })) {
                    return;
                }

                var workflowNext = workflowNextFor(target);
                if (workflowNext) {
                    if (
                        (
                            workflowNext.matches('select[name="ticket_type"]')
                            || workflowNext.matches('select[name="ticket_class"]')
                            || workflowNext.matches('input[name="ticket_departure_date"]')
                            || workflowNext.matches('[data-ticket-route-display]')
                            || workflowNext.matches('#commercial-sale-price')
                            || workflowNext.matches('#commercial-service-charge')
                            || workflowNext.matches('[data-payment-focus="received_amount"]')
                        )
                        && !supplierSelectionReady()
                    ) {
                        event.preventDefault();
                        event.stopPropagation();
                        focusSupplierSelection();
                        return;
                    }

                    if (
                        typeof window.workspaceSuppressAutosaveTemporarily === 'function' &&
                        (
                            target.matches('#commercial-sale-price') ||
                            target.matches('#commercial-service-charge')
                        )
                    ) {
                        window.workspaceSuppressAutosaveTemporarily('enter-commercial-navigation', 500);
                    }
                    if (typeof window.workspaceDebugEnterFlow === 'function') {
                        window.workspaceDebugEnterFlow('keydown-enter', {
                            target: target.tagName.toLowerCase() + (target.id ? `#${target.id}` : '') + (target.getAttribute('name') ? `[name="${target.getAttribute('name')}"]` : ''),
                            workflowNext: workflowNext.tagName.toLowerCase() + (workflowNext.id ? `#${workflowNext.id}` : '') + (workflowNext.getAttribute('name') ? `[name="${workflowNext.getAttribute('name')}"]` : ''),
                            currentPersistedServiceId: String(target.matches('#commercial-sale-price') || target.matches('#commercial-service-charge') ? 'commercial-field' : 'other'),
                        });
                    }
                    if (
                        workflowNext.matches('#legacy-service-form [name="supplier_name"]') &&
                        typeof window.workspaceFocusSupplierDropdown === 'function' &&
                        window.workspaceFocusSupplierDropdown()
                    ) {
                        event.preventDefault();
                        event.stopPropagation();
                        return;
                    }

                    if (
                        target.matches('#legacy-service-form [name="supplier_name"]') &&
                        typeof window.workspaceMaybeOpenSupplierAddModal === 'function' &&
                        window.workspaceMaybeOpenSupplierAddModal(target)
                    ) {
                        event.preventDefault();
                        event.stopPropagation();
                        return;
                    }

                    event.preventDefault();
                    event.stopPropagation();
                    scrollAndFocus(workflowNext);
                    return;
                }

                event.preventDefault();
                event.stopPropagation();

                focusNextField(target);
            }, true);
        };

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initEnterAsTab, { once: true });
            return;
        }

        initEnterAsTab();
    })();
</script>
    <script>
    (function () {
        var initPartialPaymentDueDatePicker = function () {
            var station = document.querySelector('[data-workspace-station]');

            if (!station || station.dataset.partialPaymentDueDatePickerInit === '1') {
                return;
            }

            station.dataset.partialPaymentDueDatePickerInit = '1';

            var amountInput = station.querySelector('[data-payment-focus="received_amount"]');
            var dueDateInput = station.querySelector('[data-payment-due-date]');
            var totalOutstandingInput = station.querySelector('[data-payment-total-outstanding]');
            var currentBalanceInput = station.querySelector('[data-payment-current-balance]');
            var currentInvoiceInput = station.querySelector('[data-payment-current-invoice]');

            if (!amountInput || !dueDateInput) {
                return;
            }

            var parseAmount = function (value) {
                var parsed = parseFloat(String(value || '').replace(/,/g, '').replace(/[^\d.-]/g, ''));

                return isNaN(parsed) ? 0 : parsed;
            };

            var getInvoiceDueAmount = function () {
                if (totalOutstandingInput) {
                    var totalOutstanding = parseAmount(
                        totalOutstandingInput.getAttribute('data-payment-total-due-now')
                        || totalOutstandingInput.getAttribute('data-payment-total-outstanding')
                        || totalOutstandingInput.value
                    );

                    if (totalOutstanding > 0.005) {
                        return totalOutstanding;
                    }
                }

                if (currentBalanceInput) {
                    var liveCurrentBalance = parseAmount(currentBalanceInput.value);

                    if (liveCurrentBalance > 0.005) {
                        return liveCurrentBalance;
                    }
                }

                if (currentBalanceInput) {
                    var currentBalance = parseAmount(
                        currentBalanceInput.getAttribute('data-payment-persisted-invoice-balance')
                        || currentBalanceInput.value
                    );

                    if (currentBalance > 0.005) {
                        return currentBalance;
                    }
                }

                if (currentInvoiceInput) {
                    return parseAmount(
                        currentInvoiceInput.getAttribute('data-payment-current-invoice')
                        || currentInvoiceInput.value
                    );
                }

                return 0;
            };

            var openDueDatePicker = function () {
                if (station.dataset.suppressDueDateAutoOpen === '1') {
                    return;
                }

                var receivedAmount = parseAmount(amountInput.value);
                var invoiceDueAmount = getInvoiceDueAmount();

                if (
                    receivedAmount <= 0.005
                    || invoiceDueAmount <= 0.005
                    || dueDateInput.disabled
                    || dueDateInput.closest('[hidden]') !== null
                ) {
                    return;
                }

                if (receivedAmount >= invoiceDueAmount - 0.005) {
                    return;
                }

                dueDateInput.focus();

                if (typeof dueDateInput.showPicker === 'function') {
                    try {
                        dueDateInput.showPicker();
                    } catch (error) {
                        // Browser may block automatic picker opening. The field still receives focus.
                    }
                }
            };

            amountInput.addEventListener('change', openDueDatePicker);
            amountInput.addEventListener('blur', openDueDatePicker);

            amountInput.addEventListener('keydown', function (event) {
                if (event.key !== 'Enter') {
                    return;
                }

                event.preventDefault();
                event.stopPropagation();

                openDueDatePicker();
            });

            dueDateInput.addEventListener('click', function () {
                if (typeof dueDateInput.showPicker === 'function') {
                    try {
                        dueDateInput.showPicker();
                    } catch (error) {
                        // Native date input fallback.
                    }
                }
            });
        };

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initPartialPaymentDueDatePicker, { once: true });
            return;
        }

        initPartialPaymentDueDatePicker();
    })();
</script>
<script>
    (function () {
        var initWorkspaceDocumentsPanel = function () {
            var station = document.querySelector('[data-workspace-station]');

            if (!station || station.dataset.documentsPanelInit === '1') {
                return;
            }

            station.dataset.documentsPanelInit = '1';

            var bookingIdFields = station.querySelectorAll('input[name="booking_id"]');
            var documentsForm = station.querySelector('[data-documents-upload-form]');
            var pendingMessage = station.querySelector('[data-documents-pending-message]');
            var disabledNote = station.querySelector('[data-documents-disabled-note]');
            var documentTypeField = station.querySelector('[data-document-type-select]');
            var linkedTargetField = station.querySelector('[data-document-target-select]');
            var uploadButton = documentsForm ? documentsForm.querySelector('button[type="submit"]') : null;
            var targetMapNode = station.querySelector('#workspace-document-target-map');
            var documentTargetMap = {};

            if (targetMapNode && targetMapNode.textContent) {
                try {
                    documentTargetMap = JSON.parse(targetMapNode.textContent) || {};
                } catch (error) {
                    documentTargetMap = {};
                }
            }

            if (!documentsForm) {
                return;
            }

            var syncDocumentTargets = function () {
                if (!(documentTypeField instanceof HTMLSelectElement) || !(linkedTargetField instanceof HTMLSelectElement)) {
                    return;
                }

                var documentType = String(documentTypeField.value || '').trim();
                var allowedTargets = Array.isArray(documentTargetMap[documentType]) ? documentTargetMap[documentType] : [];
                var previousValue = String(linkedTargetField.value || '').trim();
                linkedTargetField.innerHTML = '';

                if (allowedTargets.length === 0) {
                    var emptyOption = document.createElement('option');
                    emptyOption.value = '';
                    emptyOption.textContent = 'No valid linked record is available for this document type';
                    linkedTargetField.appendChild(emptyOption);
                    linkedTargetField.disabled = true;
                    if (uploadButton && documentsForm.getAttribute('aria-disabled') !== 'true') {
                        uploadButton.disabled = true;
                    }
                    return;
                }

                allowedTargets.forEach(function (target, index) {
                    var option = document.createElement('option');
                    option.value = String(target && target.value ? target.value : '');
                    option.textContent = String(target && target.label ? target.label : 'Booking File');
                    if (previousValue !== '' ? option.value === previousValue : index === 0) {
                        option.selected = true;
                    }
                    linkedTargetField.appendChild(option);
                });

                linkedTargetField.disabled = documentsForm.getAttribute('aria-disabled') === 'true';
                if (uploadButton && documentsForm.getAttribute('aria-disabled') !== 'true') {
                    uploadButton.disabled = false;
                }
            };

            var syncDocumentsState = function (bookingId) {
                var normalizedBookingId = parseInt(String(bookingId || '0'), 10) || 0;
                var isReady = normalizedBookingId > 0;

                bookingIdFields.forEach(function (field) {
                    if (field instanceof HTMLInputElement && field.form === documentsForm) {
                        field.value = String(normalizedBookingId || 0);
                    }
                });

                documentsForm.classList.toggle('legacy-documents-form--disabled', !isReady);
                documentsForm.setAttribute('aria-disabled', isReady ? 'false' : 'true');

                if (pendingMessage) {
                    pendingMessage.hidden = isReady;
                    pendingMessage.style.display = isReady ? 'none' : 'block';
                }

                if (disabledNote) {
                    disabledNote.hidden = isReady;
                }

                documentsForm.querySelectorAll('input, select, textarea, button').forEach(function (field) {
                    if (!(field instanceof HTMLElement)) {
                        return;
                    }

                    if (field instanceof HTMLInputElement && field.type === 'hidden') {
                        return;
                    }

                    if ('disabled' in field) {
                        field.disabled = !isReady;
                    }
                });

                syncDocumentTargets();
            };

            var currentBookingId = 0;
            bookingIdFields.forEach(function (field) {
                if (!(field instanceof HTMLInputElement)) {
                    return;
                }

                var value = parseInt(String(field.value || '0'), 10) || 0;
                if (value > currentBookingId) {
                    currentBookingId = value;
                }
            });

            syncDocumentsState(currentBookingId);
            if (documentTypeField instanceof HTMLSelectElement) {
                documentTypeField.addEventListener('change', syncDocumentTargets);
            }

            station.addEventListener('workspace:booking-synced', function (event) {
                syncDocumentsState(event && event.detail ? event.detail.booking_id : 0);
            });
        };

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initWorkspaceDocumentsPanel, { once: true });
            return;
        }

            initWorkspaceDocumentsPanel();
    })();
</script>
<script>
    (function () {
        var initWorkspaceToolbarFallbacks = function () {
            var station = document.querySelector('[data-workspace-station]');
            if (!station) {
                return;
            }

            var ensureModalVisible = function (selector, focusSelector, onOpen) {
                var modal = station.querySelector(selector);
                if (!(modal instanceof HTMLElement)) {
                    return;
                }

                if (modal.hidden) {
                    modal.hidden = false;
                    modal.setAttribute('aria-hidden', 'false');
                }

                if (typeof onOpen === 'function') {
                    onOpen(modal);
                }

                if (focusSelector) {
                    window.setTimeout(function () {
                        var field = modal.querySelector(focusSelector);
                        if (field instanceof HTMLElement) {
                            field.focus();
                            if (typeof field.select === 'function') {
                                field.select();
                            }
                        }
                    }, 40);
                }
            };

            var ensureReminderPanelVisible = function () {
                var panel = station.querySelector('#dock-panel-reminders');
                if (!(panel instanceof HTMLElement)) {
                    return;
                }

                panel.hidden = false;
                panel.scrollIntoView({ behavior: 'smooth', block: 'start', inline: 'nearest' });

                window.setTimeout(function () {
                    var field = panel.querySelector('[data-reminder-focus="task"]');
                    if (field instanceof HTMLElement) {
                        field.focus();
                        if (typeof field.select === 'function') {
                            field.select();
                        }
                    }
                }, 80);
            };

            var attachFallback = function (selector, fallback) {
                station.querySelectorAll(selector).forEach(function (button) {
                    if (!(button instanceof HTMLButtonElement) || button.dataset.toolbarFallbackInit === '1') {
                        return;
                    }

                    button.dataset.toolbarFallbackInit = '1';
                    button.addEventListener('click', function () {
                        window.setTimeout(function () {
                            fallback(button);
                        }, 0);
                    });
                });
            };

            attachFallback('[data-workspace-action="customer-dues-finder"]', function () {
                if (typeof window.workspaceOpenCustomerDuesModal === 'function') {
                    window.workspaceOpenCustomerDuesModal();
                    return;
                }

                ensureModalVisible('[data-customer-dues-modal]', '[data-customer-dues-search]');
            });

            attachFallback('[data-workspace-action="supplier-history-finder"]', function () {
                if (typeof window.workspaceOpenSupplierHistoryModal === 'function') {
                    window.workspaceOpenSupplierHistoryModal();
                    return;
                }

                ensureModalVisible('[data-supplier-history-modal]', '[data-supplier-history-search]');
            });

            attachFallback('[data-global-prepaid-supplier-open]', function () {
                if (typeof window.workspaceOpenGlobalPrepaidSupplierModal === 'function') {
                    window.workspaceOpenGlobalPrepaidSupplierModal();
                    return;
                }

                ensureModalVisible('[data-global-prepaid-supplier-modal]', '[data-global-prepaid-amount]', function (modal) {
                    var amountField = modal.querySelector('[data-global-prepaid-amount]');
                    if (amountField instanceof HTMLInputElement && String(amountField.value || '').trim() === '') {
                        amountField.value = '0';
                    }
                });
            });

        };

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initWorkspaceToolbarFallbacks, { once: true });
            return;
        }

        initWorkspaceToolbarFallbacks();
    })();
</script>
<script>
    (function () {
        var initWorkspaceModalFallbacks = function () {
            var station = document.querySelector('[data-workspace-station]');
            if (!station) {
                return;
            }

            var customerDuesModal = station.querySelector('[data-customer-dues-modal]');
            var customerDuesSearch = station.querySelector('[data-customer-dues-search]');
            var customerDuesCurrency = station.querySelector('[data-customer-dues-currency-filter]');
            var customerDuesBody = station.querySelector('[data-customer-dues-customers-body]');
            var customerAdvanceModal = station.querySelector('[data-customer-advance-modal]');
            var customerAdvanceAmount = station.querySelector('[data-customer-advance-amount]');
            var supplierHistoryModal = station.querySelector('[data-supplier-history-modal]');
            var supplierHistorySearch = station.querySelector('[data-supplier-history-search]');
            var prepaidModal = station.querySelector('[data-global-prepaid-supplier-modal]');

            var debounce = function (fn, wait) {
                var timer = 0;
                return function () {
                    var args = arguments;
                    window.clearTimeout(timer);
                    timer = window.setTimeout(function () {
                        fn.apply(null, args);
                    }, wait);
                };
            };

            var attachOnce = function (element, key, eventName, handler, useCapture) {
                if (!(element instanceof HTMLElement) || element.dataset[key] === '1') {
                    return;
                }

                element.dataset[key] = '1';
                element.addEventListener(eventName, handler, !!useCapture);
            };

            station.querySelectorAll('[data-customer-dues-close]').forEach(function (button) {
                attachOnce(button, 'fallbackCustomerDuesClose', 'click', function () {
                    if (typeof window.workspaceCloseCustomerDuesModal === 'function') {
                        window.workspaceCloseCustomerDuesModal();
                        return;
                    }

                    if (customerDuesModal instanceof HTMLElement) {
                        customerDuesModal.hidden = true;
                        customerDuesModal.setAttribute('aria-hidden', 'true');
                    }
                });
            });

            station.querySelectorAll('[data-customer-advance-open]').forEach(function (button) {
                attachOnce(button, 'fallbackCustomerAdvanceOpen', 'click', function () {
                    if (typeof window.workspaceOpenCustomerAdvanceModal === 'function') {
                        window.workspaceOpenCustomerAdvanceModal();
                        return;
                    }

                    if (customerAdvanceModal instanceof HTMLElement) {
                        customerAdvanceModal.hidden = false;
                        customerAdvanceModal.setAttribute('aria-hidden', 'false');
                        if (customerAdvanceAmount instanceof HTMLInputElement) {
                            customerAdvanceAmount.focus();
                            customerAdvanceAmount.select();
                        }
                    }
                });
            });

            station.querySelectorAll('[data-customer-advance-close]').forEach(function (button) {
                attachOnce(button, 'fallbackCustomerAdvanceClose', 'click', function () {
                    if (typeof window.workspaceCloseCustomerAdvanceModal === 'function') {
                        window.workspaceCloseCustomerAdvanceModal();
                        return;
                    }

                    if (customerAdvanceModal instanceof HTMLElement) {
                        customerAdvanceModal.hidden = true;
                        customerAdvanceModal.setAttribute('aria-hidden', 'true');
                    }
                });
            });

            station.querySelectorAll('[data-supplier-history-close]').forEach(function (button) {
                attachOnce(button, 'fallbackSupplierHistoryClose', 'click', function () {
                    if (typeof window.workspaceCloseSupplierHistoryModal === 'function') {
                        window.workspaceCloseSupplierHistoryModal();
                        return;
                    }

                    if (supplierHistoryModal instanceof HTMLElement) {
                        supplierHistoryModal.hidden = true;
                        supplierHistoryModal.setAttribute('aria-hidden', 'true');
                    }
                });
            });

            station.querySelectorAll('[data-global-prepaid-supplier-close]').forEach(function (button) {
                attachOnce(button, 'fallbackPrepaidClose', 'click', function () {
                    if (prepaidModal instanceof HTMLElement) {
                        prepaidModal.hidden = true;
                        prepaidModal.setAttribute('aria-hidden', 'true');
                        prepaidModal.dataset.returnToTicketType = '0';
                    }
                });
            });

            if (customerDuesSearch instanceof HTMLInputElement) {
                var runCustomerSearch = debounce(function (travelerId) {
                    if (typeof window.workspaceLoadCustomerDuesFinder === 'function') {
                        window.workspaceLoadCustomerDuesFinder({
                            query: customerDuesSearch.value || '',
                            travelerId: travelerId || 0,
                            currency: customerDuesCurrency instanceof HTMLSelectElement ? customerDuesCurrency.value : '',
                        });
                    }
                }, 220);

                attachOnce(customerDuesSearch, 'fallbackCustomerDuesInput', 'input', function () {
                    runCustomerSearch(0);
                });
                attachOnce(customerDuesSearch, 'fallbackCustomerDuesEnter', 'keydown', function (event) {
                    if (event.key !== 'Enter' && event.code !== 'NumpadEnter') {
                        return;
                    }
                    event.preventDefault();
                    event.stopPropagation();
                    if (typeof window.workspaceLoadCustomerDuesFinder === 'function') {
                        window.workspaceLoadCustomerDuesFinder({
                            query: customerDuesSearch.value || '',
                            travelerId: 0,
                            currency: customerDuesCurrency instanceof HTMLSelectElement ? customerDuesCurrency.value : '',
                        });
                    }
                }, true);
            }

            if (customerDuesCurrency instanceof HTMLSelectElement) {
                attachOnce(customerDuesCurrency, 'fallbackCustomerDuesCurrency', 'change', function () {
                    if (typeof window.workspaceLoadCustomerDuesFinder === 'function') {
                        window.workspaceLoadCustomerDuesFinder({
                            query: customerDuesSearch instanceof HTMLInputElement ? customerDuesSearch.value || '' : '',
                            travelerId: 0,
                            currency: customerDuesCurrency.value || '',
                        });
                    }
                });
            }

            if (customerDuesBody instanceof HTMLElement) {
                attachOnce(customerDuesBody, 'fallbackCustomerDuesSelect', 'click', function (event) {
                    var button = event.target instanceof HTMLElement ? event.target.closest('[data-customer-dues-select]') : null;
                    if (!(button instanceof HTMLButtonElement)) {
                        return;
                    }

                    event.preventDefault();
                    var travelerId = parseInt(String(button.dataset.customerDuesSelect || '0'), 10) || 0;
                    if (travelerId <= 0 || typeof window.workspaceLoadCustomerDuesFinder !== 'function') {
                        return;
                    }

                    window.workspaceLoadCustomerDuesFinder({
                        query: customerDuesSearch instanceof HTMLInputElement ? customerDuesSearch.value || '' : '',
                        travelerId: travelerId,
                        currency: customerDuesCurrency instanceof HTMLSelectElement ? customerDuesCurrency.value : '',
                    });
                });
            }

            if (supplierHistorySearch instanceof HTMLInputElement) {
                var runSupplierSearch = debounce(function () {
                    if (typeof window.workspaceLoadSupplierHistoryFinder === 'function') {
                        window.workspaceLoadSupplierHistoryFinder({
                            query: supplierHistorySearch.value || '',
                        });
                    }
                }, 220);

                attachOnce(supplierHistorySearch, 'fallbackSupplierSearchInput', 'input', function () {
                    runSupplierSearch();
                });
                attachOnce(supplierHistorySearch, 'fallbackSupplierSearchEnter', 'keydown', function (event) {
                    if (event.key !== 'Enter' && event.code !== 'NumpadEnter') {
                        return;
                    }
                    event.preventDefault();
                    event.stopPropagation();
                    if (typeof window.workspaceLoadSupplierHistoryFinder === 'function') {
                        window.workspaceLoadSupplierHistoryFinder({
                            query: supplierHistorySearch.value || '',
                        });
                    }
                }, true);
            }

            var paymentMethodSelect = station.querySelector('select[name="payment_method"]');
            var paymentTreasuryRow = station.querySelector('[data-payment-treasury-row]');
            var paymentTreasurySelect = station.querySelector('[data-payment-treasury-select]');
            var syncPaymentTreasuryFallback = function () {
                if (!(paymentMethodSelect instanceof HTMLSelectElement) || !(paymentTreasuryRow instanceof HTMLElement)) {
                    return;
                }

                var method = String(paymentMethodSelect.value || '').trim().toLowerCase();
                var requiresTreasury = method === 'cash' || method === 'bank_transfer';
                paymentTreasuryRow.hidden = !requiresTreasury;

                if (!requiresTreasury || !(paymentTreasurySelect instanceof HTMLSelectElement) || paymentTreasurySelect.value !== '') {
                    return;
                }

                for (var optionIndex = 0; optionIndex < paymentTreasurySelect.options.length; optionIndex += 1) {
                    var option = paymentTreasurySelect.options[optionIndex];
                    if (option && option.value !== '' && option.value !== '__add_treasury_account__') {
                        paymentTreasurySelect.value = option.value;
                        break;
                    }
                }
            };

            if (paymentMethodSelect instanceof HTMLSelectElement) {
                attachOnce(paymentMethodSelect, 'fallbackPaymentMethodChange', 'change', syncPaymentTreasuryFallback);
                attachOnce(paymentMethodSelect, 'fallbackPaymentMethodKeyup', 'keyup', syncPaymentTreasuryFallback);
                station.addEventListener('workspace:customer-selected', function () {
                    window.setTimeout(syncPaymentTreasuryFallback, 0);
                });
                window.setTimeout(syncPaymentTreasuryFallback, 0);
            }
        };

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initWorkspaceModalFallbacks, { once: true });
            return;
        }

        initWorkspaceModalFallbacks();
    })();
</script>
</section>
