<?php

$activeService = $serviceLines[0] ?? [];
$receiptRecreateDraft = is_array($receiptRecreateDraft ?? null) ? $receiptRecreateDraft : null;
$supplierPaymentRecreateDraft = is_array($supplierPaymentRecreateDraft ?? null) ? $supplierPaymentRecreateDraft : null;
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

$latestReceipt = ($customerPaymentFoundation['receipts'] ?? [])[0] ?? null;
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
$activeServiceFinalSalePrice = (float) ($activeService['finalSalePrice'] ?? 0);
$activeServiceAutoFinalSalePrice = (($activeService['type'] ?? 'air ticket') === 'air ticket'
    ? ((float) ($activeService['purchaseCost'] ?? 0))
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
$currentInvoiceAmountValue = (float) ($receivableTotals[$invoiceCurrency] ?? 0.0);
if ($currentInvoiceAmountValue <= 0.005) {
    $currentInvoiceAmountValue = array_reduce(
        $activePersistedServiceLines,
        static function (float $carry, array $row) use ($invoiceCurrency): float {
            if ((string) ($row['currency'] ?? 'PKR') !== $invoiceCurrency) {
                return $carry;
            }

            $savedFinalSale = (float) ($row['finalSalePrice'] ?? 0);
            if (abs($savedFinalSale) > 0.005) {
                return $carry + $savedFinalSale;
            }

            $autoFinalSale = ((string) ($row['type'] ?? 'air ticket') === 'air ticket'
                ? (float) ($row['purchaseCost'] ?? 0)
                : (float) ($row['salePrice'] ?? 0))
                + (float) ($row['serviceCharge'] ?? 0)
                + (float) ($row['vat'] ?? 0)
                - (float) ($row['discountAmount'] ?? 0);

            return $carry + $autoFinalSale;
        },
        0.0
    );
}
if ($currentInvoiceAmountValue <= 0.005 && $invoiceCurrency === (string) ($activeService['currency'] ?? $invoiceCurrency)) {
    $currentInvoiceAmountValue = max(0, $activeServiceFinalSalePrice);
}
$currentOutstandingAmountValue = (float) ($outstandingTotals[$invoiceCurrency] ?? 0.0);
$currentReceivedPersistedAmount = (float) ($receivedTotals[$invoiceCurrency] ?? 0.0);
$effectiveCurrentInvoiceDueValue = $currentOutstandingAmountValue > 0.005
    ? $currentOutstandingAmountValue
    : (($currentInvoiceAmountValue > 0.005 && ! $hasCustomerFinance) ? $currentInvoiceAmountValue : 0.0);
$effectiveCurrentInvoiceDueValue = $effectiveCurrentInvoiceDueValue > 0.005
    ? $effectiveCurrentInvoiceDueValue
    : ($currentInvoiceAmountValue > 0.005 ? $currentInvoiceAmountValue : 0.0);
$currentInvoiceAmount = $invoiceCurrency . ' ' . number_format($currentInvoiceAmountValue, 2);
$currentInvoiceBalance = $invoiceCurrency . ' ' . number_format($effectiveCurrentInvoiceDueValue, 2);
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
$paidOnCurrentInvoiceDisplay = $invoiceCurrency . ' ' . number_format($currentReceivedPersistedAmount, 2);
$hasCurrentInvoiceAmount = $currentInvoiceAmountValue > 0.005 || $currentReceivedPersistedAmount > 0.005 || $effectiveCurrentInvoiceDueValue > 0.005;
$paymentCurrency = $invoiceCurrency;
$totalOutstandingAmount = $sameCurrencyPreviousBalanceAmount + $effectiveCurrentInvoiceDueValue;
$totalOutstanding = $invoiceCurrency . ' ' . number_format($totalOutstandingAmount, 2);
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
    ? 'PKR ' . number_format($currentInvoiceBalancePkrEquivalentValue, 2)
    : '-';
$amountReceivedNow = '0.00';
$receiptDraftMethod = (string) ($receiptRecreateDraft['paymentMethod'] ?? 'cash');
$receiptDraftDate = (string) ($receiptRecreateDraft['receiptDate'] ?? date('Y-m-d'));
$receiptDraftReferenceNumber = (string) ($receiptRecreateDraft['referenceNumber'] ?? '');
$receiptDraftBankCardDetail = (string) ($receiptRecreateDraft['bankCardDetail'] ?? '');
$receiptDraftRemarks = (string) ($receiptRecreateDraft['remarks'] ?? 'Payment received for current invoice');
if ($receiptRecreateDraft !== null) {
    $paymentCurrency = (string) ($receiptRecreateDraft['currency'] ?? $paymentCurrency);
    $amountReceivedNow = number_format((float) ($receiptRecreateDraft['receivedAmount'] ?? 0), 2, '.', '');
}
$receiptRecreateClearUrl = url('/workspace?booking_id=' . (int) ($workspaceBooking['id'] ?? 0) . '#dock-panel-payments');
$supplierDraftName = (string) ($supplierPaymentRecreateDraft['supplier'] ?? '');
$supplierDraftDate = (string) ($supplierPaymentRecreateDraft['paymentDate'] ?? date('Y-m-d'));
$supplierDraftCurrency = (string) ($supplierPaymentRecreateDraft['currency'] ?? 'PKR');
$supplierDraftAmount = number_format((float) ($supplierPaymentRecreateDraft['paidAmount'] ?? 0), 2, '.', '');
$supplierDraftMethod = (string) ($supplierPaymentRecreateDraft['paymentMethod'] ?? 'cash');
$supplierDraftReferenceNumber = (string) ($supplierPaymentRecreateDraft['referenceNumber'] ?? '');
$supplierDraftBankCardDetail = (string) ($supplierPaymentRecreateDraft['bankCardDetail'] ?? '');
$supplierDraftRemarks = (string) ($supplierPaymentRecreateDraft['remarks'] ?? '');
$supplierRecreateClearUrl = url('/workspace?booking_id=' . (int) ($workspaceBooking['id'] ?? 0) . '#dock-panel-suppliers');
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
$paymentAllocationsJson = json_encode(
    $customerPaymentFoundation['allocations'] ?? [],
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
$otherPayable = 'PKR 0.00';
$profitLoss = $workspaceBooking['profitLoss'];
$activeServiceFrTxRawValue = ((string) ($activeService['type'] ?? 'air ticket') === 'air ticket')
    ? ((float) ($activeService['salePrice'] ?? 0) + $serviceTaxTotal($activeService))
    : (float) ($activeService['purchaseCost'] ?? 0);

$activeServiceLossDelta = max(0, round($activeServiceFrTxRawValue - $activeServiceFinalSalePrice, 2));
$activeServiceHasLoss = $activeServiceLossDelta > 0.005;
$passengerName = (string) (($activeService['passengerName'] ?? '') !== '' ? $activeService['passengerName'] : ($leadTraveler['fullName'] !== '' ? $leadTraveler['fullName'] : $workspaceBooking['lead']));
$sectorDescription = trim((string) (($activeService['sectorFrom'] ?? '') . (($activeService['sectorTo'] ?? '') !== '' ? '-' . $activeService['sectorTo'] : '')));
$sectorDescription = $sectorDescription !== '' ? $sectorDescription : (string) ($activeService['remarks'] ?? '');
$routeLabel = trim((string) (($activeService['sectorFrom'] ?? '') . (($activeService['sectorTo'] ?? '') !== '' ? '-' . $activeService['sectorTo'] : '')));
$serviceReference = (string) ($activeService['ticketNumber'] ?? '');
$serviceReference = $serviceReference !== '' ? $serviceReference : (string) ($activeService['lineNumber'] ?? 'SV-DRAFT');
$ledgerUrl = $workspaceBooking['id'] > 0
    ? url('/workspace/output?booking_id=' . (int) $workspaceBooking['id'] . '&doc=account_statement')
    : '#';
$duesFinderUrl = url('/workspace/customers/dues-finder');
$supplierHistoryFinderUrl = url('/workspace/suppliers/history-finder');
$invoiceNoLabel = $workspaceBooking['id'] > 0 ? $workspaceBooking['number'] : 'Draft';
$showWorkspaceDebug = app_debug_tools_enabled() && (string) ($_GET['debug_ui'] ?? '') === '1';
$canPostServiceEvents = in_array((string) ($user['roleCode'] ?? $user['role_code'] ?? ''), ['super_admin', 'branch_admin'], true);
$invoiceOutstandingAmount = $sameCurrencyPreviousBalanceAmount + $effectiveCurrentInvoiceDueValue;
$invoiceReceivedAmount = $currentReceivedPersistedAmount;
$hasRealInvoiceState = $currentInvoiceAmountValue > 0.005 || $effectiveCurrentInvoiceDueValue > 0.005 || $hasActiveServices;
$paymentStatus = 'Draft';
$paymentStatusClass = 'draft';
if ($workspaceBooking['id'] > 0 || $hasRealInvoiceState || $sameCurrencyPreviousBalanceAmount > 0.005) {
    if ($invoiceOutstandingAmount <= 0.005 && ($hasRealInvoiceState || $sameCurrencyPreviousBalanceAmount > 0.005)) {
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
?>

<section class="legacy-workspace" data-workspace-station data-service-engine data-has-services="<?= $hasActiveServices ? '1' : '0' ?>" data-has-selected-customer="<?= ((int) ($selectedTravelerProfile['id'] ?? 0) > 0 || trim((string) ($workspaceBooking['lead'] ?? '')) !== '') ? '1' : '0' ?>" data-new-booking-url="<?= e(url('/workspace?new=1&focus=customer')) ?>" data-autosave-invoice-url="<?= e(url('/workspace/autosave/invoice')) ?>" data-autosave-service-url="<?= e(url('/workspace/autosave/service')) ?>" data-supplier-advance-lookup-url="<?= e(url('/suppliers/advances/available')) ?>" data-can-void-financials="<?= $canPostServiceEvents ? '1' : '0' ?>" data-debug-tools-enabled="<?= $showWorkspaceDebug ? '1' : '0' ?>">
    <div class="workspace-feedback" data-workspace-feedback aria-live="polite"></div>
    <?php if ($showWorkspaceDebug && $serviceSaveDebugJson !== null): ?>
        <pre class="commercial-debug-block" style="margin:8px 0 12px; white-space:pre-wrap;">Service save debug
<?= e($serviceSaveDebugJson) ?></pre>
    <?php endif; ?>

    <div class="legacy-command-bar">
        <div class="legacy-command-group">
            <button class="btn btn-primary btn-sm" type="button" accesskey="n" data-workspace-action="new-booking">New Invoice</button>
            <button class="btn btn-sm" type="button" data-workspace-action="add-traveler" onclick="return window.workspaceOpenStandaloneCustomerModal && window.workspaceOpenStandaloneCustomerModal(event)">Find Customer</button>
            <button class="btn btn-sm" type="button" data-workspace-action="new-customer" onclick="return window.workspaceOpenStandaloneNewCustomerModal && window.workspaceOpenStandaloneNewCustomerModal(event)">New Customer</button>
            <button class="btn btn-sm" type="button" data-workspace-action="customer-dues-finder">Receive Customer Payment</button>
            <button class="btn btn-sm" type="button" data-workspace-action="supplier-history-finder">Find Supplier Payment</button>
            <button class="btn btn-sm" type="button" data-global-prepaid-supplier-open>Prepaid Supplier Payment</button>
            <button class="btn btn-sm" type="button" data-workspace-action="add-service" data-workflow-control="add-service" <?= $hasActiveServices ? '' : 'disabled' ?>>Add Service</button>
            <button class="btn btn-sm" type="button" data-workspace-action="print">Print</button>
        </div>
        <form id="workspace-search-form" class="legacy-quick-search" method="get" action="<?= e(url('/workspace')) ?>">
            <label for="workspace-search">Quick Search</label>
            <input id="workspace-search" type="text" name="q" value="<?= e($currentSearchTerm) ?>" placeholder="Invoice / customer / mobile / passport / PNR / supplier">
            <button class="btn btn-primary btn-sm" type="submit" accesskey="s" data-workspace-search-submit>Search</button>
        </form>
    </div>

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
        <input type="hidden" name="booking_id" value="<?= e((string) $workspaceBooking['id']) ?>">
        <input type="hidden" name="selected_customer_id" value="<?= e((string) ($selectedTravelerProfile['id'] ?? 0)) ?>" data-booking-selected-customer-id>
        <input type="hidden" name="branch_id" value="<?= e((string) $workspaceBooking['branchId']) ?>">
        <input type="hidden" name="due_date" value="<?= e($invoiceDueDate) ?>" data-booking-due-date-field>
        <input type="hidden" name="contact_mobile" value="<?= e($workspaceBooking['mobile']) ?>" data-booking-mobile-field>
        <input type="hidden" name="passport_number" value="<?= e($workspaceBooking['passport']) ?>" data-booking-passport-field>
        <input type="hidden" name="departure_date" value="<?= e($workspaceBooking['departureDate']) ?>">
        <input type="hidden" name="return_date" value="<?= e($workspaceBooking['returnDate']) ?>">
        <input type="hidden" name="party_notes" value="<?= e($workspaceBooking['partyNotes']) ?>">

        <div class="legacy-invoice-header__row legacy-invoice-header__row--primary">
            <label class="legacy-field legacy-field--customer station-field--autocomplete legacy-invoice-header__customer" data-customer-inline-search>
                <span>Customer Name</span>
                <input id="lead-traveler-focus" type="text" name="lead_traveler_name" value="<?= e($workspaceBooking['lead']) ?>" autocomplete="off" placeholder="Type customer name / family ID / passport / mobile" data-customer-autocomplete-input onfocus="window.workspaceOpenInlineCustomerLookup && window.workspaceOpenInlineCustomerLookup(this)" onclick="window.workspaceOpenInlineCustomerLookup && window.workspaceOpenInlineCustomerLookup(this)" oninput="window.workspaceHandleInlineCustomerLookupInput && window.workspaceHandleInlineCustomerLookupInput(this)" onkeydown="window.workspaceHandleInlineCustomerLookupKeydown && window.workspaceHandleInlineCustomerLookupKeydown(event, this)">
                <div class="customer-inline-picker" data-customer-autocomplete-panel hidden>
                    <div class="customer-inline-picker__hint">Search by name, family ID, passport, phone, village, district, or notes.</div>
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
        <label class="legacy-field legacy-field--service-type"><span>Service Type</span><select name="service_type" data-service-field="type"><?php foreach ($serviceTypeOptions as $serviceTypeOption): ?><option value="<?= e($serviceTypeOption) ?>" <?= $serviceTypeOption === ($activeService['type'] ?? 'air ticket') ? 'selected' : '' ?>><?= e(ucwords($serviceTypeOption)) ?></option><?php endforeach; ?></select></label>
        <label class="legacy-field legacy-field--ticket-ref"><span data-service-ref-label>Ticket No. / Ref No.</span><input type="text" name="ticket_number" data-ticket-field="ticket_number" value="<?= e($serviceReference) ?>"></label>
        <label class="legacy-field legacy-field--pnr"><span data-service-second-ref-label>PNR.#</span><input type="text" name="ticket_pnr" data-ticket-field="pnr" value="<?= e((string) ($activeService['pnr'] ?? '')) ?>"></label>
        <label class="legacy-field legacy-field--supplier"><span data-service-supplier-label>Tkt.Purchase From</span><input type="text" name="supplier_name" list="service-supplier-options" data-service-field="supplier" value="<?= e((string) ($activeService['supplier'] ?? '')) ?>"></label>
        <label class="legacy-field legacy-field--ticket-type" data-air-only>
            <span>Ticket Type</span>
            <select name="ticket_type" data-ticket-field="ticket_type">
                <?php $ticketTypeValue = strtolower(trim((string) ($activeService['ticketType'] ?? ''))); ?>
                <option value="" <?= $ticketTypeValue === '' ? 'selected' : '' ?>></option>
                <option value="local" <?= $ticketTypeValue === 'local' ? 'selected' : '' ?>>Local</option>
                <option value="international" <?= $ticketTypeValue === 'international' ? 'selected' : '' ?>>International</option>
            </select>
        </label>
        <label class="legacy-field legacy-field--class" data-air-only>
            <span>Class</span>
            <select name="ticket_class" data-ticket-field="class">
                <?php $ticketClassValue = strtolower(trim((string) ($activeService['class'] ?? ''))); ?>
                <option value="" <?= $ticketClassValue === '' ? 'selected' : '' ?>></option>
                <option value="economy" <?= $ticketClassValue === 'economy' ? 'selected' : '' ?>>Economy</option>
                <option value="business" <?= $ticketClassValue === 'business' ? 'selected' : '' ?>>Business</option>
                <option value="first" <?= $ticketClassValue === 'first' ? 'selected' : '' ?>>First</option>
            </select>
        </label>
        <label class="legacy-field legacy-field--xs" data-air-only><span>Conj.</span><input type="text" value="" readonly></label>
        <label class="legacy-field legacy-field--attach" data-air-only><span>Attach Last Ticket #</span><input type="text" value="" readonly></label>
        <label class="legacy-field legacy-field--place" data-air-only><span>Place of services (VAT)</span><input type="text" value="OTHER" readonly></label>
        <label class="legacy-field legacy-field--airline" data-air-only><span>Airline/Agent (CR)</span><input type="text" name="ticket_airline" data-ticket-field="airline" value="<?= e((string) ($activeService['airline'] ?? '')) ?>"></label>
       <label class="legacy-field legacy-field--sales"><span>Sales Person</span><input type="text" value="<?= e((string) ($user['username'] ?? $user['email'] ?? '')) ?>" readonly></label>

        <label class="legacy-field legacy-field--xs" data-air-only><span>XO</span><input type="text" value="" readonly></label>
        <label class="legacy-field legacy-field--date"><span>Validation Date</span><input type="date" name="due_date" data-service-field="due_date" value="<?= e((string) ($activeService['dueDate'] ?? '')) ?>"></label>
        <label class="legacy-field legacy-field--booking-ref"><span>Booking Ref.</span><input type="text" data-service-field="lineNumber" value="<?= e((string) ($activeService['lineNumber'] ?? 'SV-DRAFT')) ?>" readonly></label>
        <label class="legacy-field legacy-field--status"><span>Status</span><select name="service_status" data-service-field="status"><?php foreach ($serviceStatusOptions as $statusOption): ?><option value="<?= e($statusOption) ?>" <?= $statusOption === ($activeService['status'] ?? 'Open') ? 'selected' : '' ?>><?= e(substr($statusOption, 0, 3)) ?></option><?php endforeach; ?></select></label>
        <label class="legacy-field legacy-field--xs" hidden aria-hidden="true"><span>Curr.</span><select data-service-field="currency-mirror" tabindex="-1"><?php foreach (['PKR', 'AED', 'USD'] as $currencyOption): ?><option value="<?= e($currencyOption) ?>" <?= $currencyOption === (string) ($activeService['currency'] ?? $invoiceCurrency) ? 'selected' : '' ?>><?= e($currencyOption) ?></option><?php endforeach; ?></select></label>
        <label class="legacy-field legacy-field--date"><span>Dep. Date</span><input type="date" name="ticket_departure_date" data-ticket-field="departure_date" value="<?= e((string) ($activeService['departureDate'] ?? '')) ?>"></label>
        <label class="legacy-field legacy-field--route"><span>Route</span><input type="text" value="<?= e($routeLabel) ?>" data-ticket-route-display></label>
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
        </div>
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

<button class="legacy-service-save btn btn-primary btn-sm" type="submit" data-service-submit data-manual-service-save><?= (int) ($activeService['serviceId'] ?? 0) > 0 ? 'Update Service' : ($hasActiveServices ? 'Save New Service' : 'Save First Service') ?></button>
*/ ?>    </form>

    <?php if ($canPostServiceEvents): ?>
    <?php
        $activeServiceId = (int) ($activeService['serviceId'] ?? 0);
        $activeServiceStatus = strtolower(trim((string) ($activeService['status'] ?? '')));
        $activeServiceType = strtolower(trim((string) ($activeService['type'] ?? '')));
        $showCancelAction = $activeServiceId > 0 && $activeServiceStatus !== 'cancelled';
        $showRefundAction = $activeServiceId > 0;
        $showSettlementAction = $activeServiceId > 0 && $activeServiceStatus === 'cancelled';
        $showReissueAction = $activeServiceId > 0 && $activeServiceType === 'air ticket';
    ?>
    <form class="legacy-service-event-bar" method="post" action="<?= e(url('/workspace/services/cancel')) ?>" data-service-event-bar="cancel" data-workflow-gate="service-entry" <?= $showCancelAction ? '' : 'hidden' ?> onsubmit="return confirm('Record cancellation for this service line? This will not post a refund yet.');">
        <?= \App\Helpers\Csrf::input() ?>
        <input type="hidden" name="booking_id" value="<?= e((string) $workspaceBooking['id']) ?>">
        <input type="hidden" name="service_id" value="<?= e((string) ($activeService['serviceId'] ?? 0)) ?>" data-service-cancel-id>
        <label class="legacy-service-event-field"><span>Date</span><input type="date" name="cancel_event_date" value="<?= e(date('Y-m-d')) ?>" aria-label="Cancellation date"></label>
        <label class="legacy-service-event-field"><span>Reason</span><input type="text" name="cancel_reason" value="" placeholder="Cancellation reason" minlength="5" maxlength="1000" required></label>
        <label class="legacy-service-event-field"><span>Note</span><input type="text" name="cancel_notes" value="" placeholder="Optional note" maxlength="4000"></label>
        <button class="btn btn-sm legacy-service-event-action" type="submit" data-service-cancel-button <?= (int) ($activeService['serviceId'] ?? 0) > 0 && (string) ($activeService['status'] ?? '') !== 'Cancelled' ? '' : 'disabled' ?>>Cancel Service</button>
    </form>
    <form class="legacy-service-event-bar legacy-service-event-bar--refund" method="post" action="<?= e(url('/workspace/services/refund')) ?>" data-service-event-bar="refund" data-workflow-gate="service-entry" <?= $showRefundAction ? '' : 'hidden' ?> onsubmit="return confirm('Post refund for this service line? This will create accounting journal entries.');">
        <?= \App\Helpers\Csrf::input() ?>
        <input type="hidden" name="booking_id" value="<?= e((string) $workspaceBooking['id']) ?>">
        <input type="hidden" name="service_id" value="<?= e((string) ($activeService['serviceId'] ?? 0)) ?>" data-service-refund-id>
        <label class="legacy-service-event-field"><span>Date</span><input type="date" name="refund_event_date" value="<?= e(date('Y-m-d')) ?>" aria-label="Refund date"></label>
        <label class="legacy-service-event-field"><span>Customer Refund</span><input type="number" name="customer_refund_amount" value="0.00" min="0" step="0.01" aria-label="Customer refund amount"></label>
        <label class="legacy-service-event-field"><span>Supplier Refund</span><input type="number" name="supplier_refund_amount" value="0.00" min="0" step="0.01" aria-label="Supplier refund received"></label>
        <label class="legacy-service-event-field"><span>Method</span><select name="refund_payment_method" aria-label="Refund payment method">
            <?php foreach (['cash' => 'Cash', 'bank_transfer' => 'Bank', 'debit_card' => 'Debit Card', 'credit_card' => 'Credit Card'] as $refundMethodValue => $refundMethodLabel): ?>
                <option value="<?= e($refundMethodValue) ?>"><?= e($refundMethodLabel) ?></option>
            <?php endforeach; ?>
        </select></label>
        <label class="legacy-service-event-field"><span>Reason</span><input type="text" name="refund_reason" value="" placeholder="Refund reason" minlength="5" maxlength="1000" required></label>
        <button class="btn btn-sm legacy-service-event-action" type="submit" data-service-refund-button <?= (int) ($activeService['serviceId'] ?? 0) > 0 ? '' : 'disabled' ?>>Post Refund</button>
    </form>
    <form class="legacy-service-event-bar legacy-service-event-bar--settlement" method="post" action="<?= e(url('/workspace/services/cancellation-financials')) ?>" data-service-event-bar="settlement" data-workflow-gate="service-entry" <?= $showSettlementAction ? '' : 'hidden' ?> onsubmit="return confirm('Post cancellation financial adjustment for this service line?');">
        <?= \App\Helpers\Csrf::input() ?>
        <input type="hidden" name="booking_id" value="<?= e((string) $workspaceBooking['id']) ?>">
        <input type="hidden" name="service_id" value="<?= e((string) ($activeService['serviceId'] ?? 0)) ?>" data-service-settlement-id>
        <label class="legacy-service-event-field"><span>Date</span><input type="date" name="settlement_event_date" value="<?= e(date('Y-m-d')) ?>" aria-label="Settlement date"></label>
        <label class="legacy-service-event-field"><span>Customer Penalty</span><input type="number" name="customer_penalty_amount" value="0.00" min="0" step="0.01" aria-label="Customer penalty amount"></label>
        <label class="legacy-service-event-field"><span>Supplier Penalty</span><input type="number" name="supplier_penalty_amount" value="0.00" min="0" step="0.01" aria-label="Supplier penalty amount"></label>
        <label class="legacy-service-event-field"><span>Reason</span><input type="text" name="settlement_reason" value="" placeholder="Settlement reason" minlength="5" maxlength="1000" required></label>
        <button class="btn btn-sm legacy-service-event-action" type="submit" data-service-settlement-button <?= (int) ($activeService['serviceId'] ?? 0) > 0 && (string) ($activeService['status'] ?? '') === 'Cancelled' ? '' : 'disabled' ?>>Settle Cancel</button>
    </form>
    <form class="legacy-service-event-bar legacy-service-event-bar--reissue" method="post" action="<?= e(url('/workspace/services/reissue')) ?>" data-service-event-bar="reissue" data-workflow-gate="service-entry" <?= $showReissueAction ? '' : 'hidden' ?> onsubmit="return confirm('Record reissue for this service line?');">
        <?= \App\Helpers\Csrf::input() ?>
        <input type="hidden" name="booking_id" value="<?= e((string) $workspaceBooking['id']) ?>">
        <input type="hidden" name="service_id" value="<?= e((string) ($activeService['serviceId'] ?? 0)) ?>" data-service-reissue-id>
        <label class="legacy-service-event-field"><span>Date</span><input type="date" name="reissue_event_date" value="<?= e(date('Y-m-d')) ?>" aria-label="Reissue date"></label>
        <label class="legacy-service-event-field"><span>New Ticket</span><input type="text" name="new_ticket_number" value="" placeholder="New ticket no." maxlength="50" required></label>
        <label class="legacy-service-event-field"><span>New PNR</span><input type="text" name="new_pnr" value="" placeholder="New PNR" maxlength="50"></label>
        <label class="legacy-service-event-field"><span>Customer Extra</span><input type="number" name="fare_difference_amount" value="0.00" min="0" step="0.01" aria-label="Fare difference charged to customer"></label>
        <label class="legacy-service-event-field"><span>Service Fee</span><input type="number" name="reissue_service_fee_amount" value="0.00" min="0" step="0.01" aria-label="Service fee"></label>
        <label class="legacy-service-event-field"><span>Supplier Extra</span><input type="number" name="supplier_cost_difference_amount" value="0.00" min="0" step="0.01" aria-label="Supplier cost difference"></label>
        <label class="legacy-service-event-field"><span>Reason</span><input type="text" name="reissue_reason" value="" placeholder="Reissue reason" minlength="5" maxlength="1000" required></label>
        <button class="btn btn-sm legacy-service-event-action" type="submit" data-service-reissue-button <?= (int) ($activeService['serviceId'] ?? 0) > 0 && (string) ($activeService['type'] ?? '') === 'air ticket' ? '' : 'disabled' ?>>Reissue</button>
    </form>
    <?php endif; ?>

    <datalist id="service-supplier-options">
        <?php foreach ($serviceSupplierOptions as $supplierOption): ?>
            <option value="<?= e((string) $supplierOption['name']) ?>">
        <?php endforeach; ?>
    </datalist>
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
            <table class="legacy-table">
                <thead><tr><th>Sr.</th><th>Passenger Name</th><th>Relation</th><th>Passport No.</th><th>Nationality</th><th>Service Link</th><th>Remarks</th></tr></thead>
                <tbody>
                <?php foreach ($travelers as $index => $traveler): ?>
                    <tr data-traveler-row data-traveler-index="<?= e((string) $index) ?>" class="<?= $index === 0 ? 'is-active' : '' ?>">
                        <td><?= e((string) ($index + 1)) ?></td>
                        <td><?= e((string) $traveler['fullName']) ?></td>
                        <td><?= e($index === 0 ? 'Self' : (string) $traveler['type']) ?></td>
                        <td><?= e((string) $traveler['passportNo']) ?></td>
                        <td><?= e((string) $traveler['nationality']) ?></td>
                        <td>All</td>
                        <td><?= e($index === 0 ? 'Lead Traveler' : (string) $traveler['notes']) ?></td>
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
                <label><span>Soto Fare</span><input id="commercial-soto-fare" type="number" form="legacy-service-form" name="soto_fare" step="0.01" value="<?= e((string) ($activeService['sotoFare'] ?? 0)) ?>" data-service-metric="soto_fare"></label>
                <label><span>AQ/YR/PK</span><input id="commercial-aqyrpk-amount" type="number" form="legacy-service-form" name="aq_yr_pk_amount" step="0.01" value="<?= e((string) ($activeService['aqYrPkAmount'] ?? 0)) ?>" data-service-metric="aq_yr_pk_amount"></label>
                <label><span>OTH.</span><input id="commercial-oth-amount" type="number" form="legacy-service-form" name="oth_amount" step="0.01" value="<?= e((string) ($activeService['othAmount'] ?? 0)) ?>" data-service-metric="oth_amount"></label>
                <label><span>Taxes</span><input id="commercial-taxes" type="number" form="legacy-service-form" name="taxes" step="0.01" value="<?= e((string) ($activeService['taxes'] ?? 0)) ?>" data-service-metric="tax"></label>
                <label><span>Fr+Tx.</span><input id="commercial-airline-payable" type="text" value="<?= e($activeServiceFrTxValue) ?>" readonly data-airline-payable-field></label>
                <input id="commercial-purchase-cost" type="hidden" form="legacy-service-form" name="purchase_cost" value="<?= e((string) ($activeService['purchaseCost'] ?? 0)) ?>" data-service-metric="cost">
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
                        <div class="legacy-client-row__control"><input type="number" value="0.00" readonly></div>
                    </div>
                    <div class="legacy-client-row">
                        <div class="legacy-client-row__label">Serv.Amount</div>
                        <div class="legacy-client-row__control"><input id="commercial-service-charge" type="number" form="legacy-service-form" name="service_charge" step="0.01" value="<?= e((string) ($activeService['serviceCharge'] ?? 0)) ?>" data-service-metric="service_charge"></div>
                    </div>
                    <div class="legacy-client-row">
                        <div class="legacy-client-row__label">Disc.%</div>
                        <div class="legacy-client-row__control"><input type="number" value="0.00" readonly></div>
                    </div>
                    <div class="legacy-client-row">
                        <div class="legacy-client-row__label">Disc.Amt.</div>
                        <div class="legacy-client-row__control"><input id="commercial-discount-amount" type="number" form="legacy-service-form" name="discount_amount" step="0.01" value="<?= e((string) ($activeService['discountAmount'] ?? 0)) ?>" data-service-discount></div>
                    </div>
                    <div class="legacy-client-row">
                        <div class="legacy-client-row__label legacy-vat-label">Vat %</div>
                        <div class="legacy-client-row__control"><input type="number" value="0.00" readonly></div>
                    </div>
                    <div class="legacy-client-row">
                        <div class="legacy-client-row__label">Vat Output</div>
                        <div class="legacy-client-row__control"><input id="commercial-vat-output" type="number" form="legacy-service-form" name="vat" step="0.01" value="<?= e((string) ($activeService['vat'] ?? 0)) ?>" data-service-metric="vat"></div>
                    </div>
                    <div class="legacy-client-row">
                        <div class="legacy-client-row__label">Final Sale Amount</div>
                        <div class="legacy-client-row__control"><input id="commercial-final-sale-price" type="number" form="legacy-service-form" name="final_sale_price" step="0.01" value="<?= e(number_format($activeServiceFinalSalePrice, 2, '.', '')) ?>" data-service-final-sale data-manual-override="<?= $activeServiceHasManualFinalSaleOverride ? '1' : '0' ?>"></div>
                    </div>
                    <div class="legacy-client-row" data-loss-amount-panel <?= $activeServiceHasLoss ? '' : 'hidden' ?>>
                        <div class="legacy-client-row__label">Loss</div>
                        <div class="legacy-client-row__control">
                            <input id="commercial-loss-amount" type="number" step="0.01" value="<?= e(number_format($activeServiceLossDelta, 2, '.', '')) ?>" data-service-loss-amount readonly>
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
            <input type="hidden" value="<?= e($invoiceCurrency . ' ' . number_format($sameCurrencyPreviousBalanceAmount, 2)) ?>" data-payment-previous-balance="<?= e((string) $sameCurrencyPreviousBalanceAmount) ?>" data-payment-previous-balance-map="<?= e(json_encode($previousBalanceTotals, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?: '{}') ?>" data-payment-open-balance-map="<?= e(json_encode($customerOpenBalanceTotals, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?: '{}') ?>">
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
            <div class="legacy-payment-panels">
                <section class="legacy-payment-panel legacy-payment-panel--invoice">
                    <div class="legacy-payment-panel__title">CURRENT INVOICE</div>
                    <div class="legacy-payment-panel__body legacy-payment-panel__body--form">
                        <label><span>Invoice Currency</span><select id="commercial-invoice-currency" name="currency" form="legacy-service-form" data-service-field="currency"><?php foreach (['PKR', 'AED', 'USD'] as $currencyOption): ?><option value="<?= e($currencyOption) ?>" <?= $currencyOption === (string) ($activeService['currency'] ?? $invoiceCurrency) ? 'selected' : '' ?>><?= e($currencyOption) ?></option><?php endforeach; ?></select></label>
                        <label data-payment-current-invoice-row<?= $hasCurrentInvoiceAmount ? '' : ' hidden' ?>><span>Invoice Amount</span><input id="commercial-payment-current-invoice" type="text" value="<?= e($currentInvoiceAmount) ?>" data-payment-current-invoice="<?= e((string) $currentInvoiceAmountValue) ?>" data-payment-currency="<?= e($invoiceCurrency) ?>" readonly></label>
                        <label data-payment-paid-current-invoice-row<?= $currentReceivedPersistedAmount > 0.005 ? '' : ' hidden' ?>><span>Paid on This Invoice</span><input id="commercial-payment-already-received" type="text" value="<?= e($paidOnCurrentInvoiceDisplay) ?>" data-payment-already-received data-payment-persisted-received="<?= e((string) $currentReceivedPersistedAmount) ?>" readonly></label>
                        <label data-payment-current-balance-row<?= $hasCurrentInvoiceAmount ? '' : ' hidden' ?>><span>Invoice Balance</span><input id="commercial-payment-current-balance" class="legacy-red-text" type="text" value="<?= e($currentInvoiceBalance) ?>" data-payment-current-balance data-payment-persisted-invoice-balance="<?= e((string) $effectiveCurrentInvoiceDueValue) ?>" readonly></label>
                    </div>
                </section>
                <section class="legacy-payment-panel legacy-payment-panel--open-balance">
                    <div class="legacy-payment-panel__title">CUSTOMER OPEN BALANCE</div>
                    <div class="legacy-payment-panel__body legacy-payment-panel__body--balances">
                        <div data-payment-previous-balances-block<?= $visibleCustomerOpenBalanceTotals !== [] ? '' : ' hidden' ?>>
                            <div data-payment-previous-balance-list>
                                <?php foreach ($visibleCustomerOpenBalanceTotals as $currencyCode => $amount): ?>
                                    <label><span><?= e((string) $currencyCode) ?></span><input class="legacy-red-text" type="text" value="<?= e((string) $currencyCode . ' ' . number_format((float) $amount, 2)) ?>" readonly></label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="workspace-feedback workspace-feedback--inline" data-payment-no-previous-balance hidden style="display:none !important;" aria-hidden="true"></div>
                    </div>
                </section>
                <section class="legacy-payment-panel legacy-payment-panel--receive">
                    <div class="legacy-payment-panel__title">RECEIVE PAYMENT</div>
                    <div class="legacy-payment-panel__body legacy-payment-panel__body--form">
                        <label><span>Payment Currency</span><select name="receipt_currency" data-payment-currency-select><?php foreach (['PKR', 'AED', 'USD'] as $currencyOption): ?><option value="<?= e($currencyOption) ?>" <?= $currencyOption === $paymentCurrency ? 'selected' : '' ?>><?= e($currencyOption) ?></option><?php endforeach; ?></select></label>
                        <label><span data-payment-balance-label>Balance in Payment Currency (<?= e($paymentCurrency) ?>)</span><input id="commercial-payment-total-outstanding" class="legacy-red-text" type="text" value="<?= e($totalOutstanding) ?>" data-payment-total-outstanding="<?= e((string) $totalOutstandingAmount) ?>" data-payment-total-due-now="<?= e((string) $totalOutstandingAmount) ?>" readonly></label>
                        <label<?= $showCurrentInvoiceBalancePkrEquivalent ? '' : ' hidden' ?> data-payment-current-balance-pkr-row><span>PKR Equivalent of Current Balance</span><input id="commercial-payment-current-balance-pkr" type="text" value="<?= e($currentInvoiceBalancePkrEquivalent) ?>" data-payment-current-balance-pkr data-payment-pkr-rate="<?= e((string) ($currentInvoiceBalancePkrRate ?? 0)) ?>" readonly></label>
                        <label>
                            <span>Amount Receiving</span>
                            <input
                                type="number"
                                name="received_amount"
                                step="0.01"
                                value="<?= e($amountReceivedNow) ?>"
                                data-payment-focus="received_amount"
                                data-payment-partial-date-trigger
                            >
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
                        <label data-payment-return-row hidden><span>Return Amount</span><input id="commercial-payment-return-amount" class="legacy-red-text" type="text" value="PKR 0.00" readonly data-payment-return-amount></label>
                        <label><span>Payment Method</span><select name="payment_method"><?php foreach (['cash' => 'Cash', 'bank_transfer' => 'Bank Transfer', 'debit_card' => 'Debit Card', 'credit_card' => 'Credit Card'] as $paymentMethodValue => $paymentMethodLabel): ?><option value="<?= e($paymentMethodValue) ?>" <?= $receiptDraftMethod === $paymentMethodValue ? 'selected' : '' ?>><?= e($paymentMethodLabel) ?></option><?php endforeach; ?></select></label>
                    </div>
                </section>
                <section class="legacy-payment-panel legacy-payment-panel--actions">
                    <div class="legacy-payment-panel__title">ACTIONS</div>
                    <div class="legacy-payment-panel__body legacy-payment-panel__body--actions">
                        <div class="legacy-payment-actions">
                            <button class="btn btn-primary btn-sm legacy-payment-primary" type="button" name="receipt_action" value="save" data-payment-submit-action="save" data-payment-action="save-payment">Save Payment</button>
                            <button class="btn btn-success btn-sm legacy-payment-receipt" type="button" data-payment-action="print-receipt" data-payment-print-url="<?= e($latestReceiptUrl) ?>" data-payment-latest-receipt-id="<?= e((string) $latestReceiptId) ?>">Print Receipt</button>
                            <button class="btn btn-sm" type="button" data-workspace-action="payment-history" data-workflow-control="payment-history" data-payment-action="payment-history">Payment History</button>
                            <a class="btn btn-sm" href="<?= e($ledgerUrl) ?>" <?= $workspaceBooking['id'] > 0 ? 'target="_blank" rel="noopener"' : '' ?> data-payment-action="customer-ledger" data-customer-ledger-link>View Customer Ledger</a>
                            <button class="btn btn-sm" type="button" data-payment-exchange-settlement data-payment-action="exchange-settlement">Exchange Settlement</button>
                        </div>
                    </div>
                </section>
            </div>
            <input type="hidden" value="0.00" data-quick-receive-input>
            <input type="hidden" name="receipt_date" value="<?= e($receiptDraftDate) ?>">
            <input type="hidden" name="reference_number" value="<?= e($receiptDraftReferenceNumber) ?>">
            <input type="hidden" name="bank_card_detail" value="<?= e($receiptDraftBankCardDetail) ?>">
            <input type="hidden" name="charges_amount" value="0.00">
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
        </form>
    </div>

    <div class="legacy-highlight-strip">
        <div class="legacy-highlight legacy-highlight--red"><span>Airline Payable</span><strong id="commercial-airline-payable-summary" data-airline-payable-summary><?= e($airlinePayable) ?></strong></div>
        <div class="legacy-highlight legacy-highlight--red"><span>Receivable (Client)</span><strong id="commercial-client-receivable-summary" data-client-receivable-summary><?= e($clientReceivable) ?></strong></div>
        <div class="legacy-highlight legacy-highlight--orange"><span>Other Payable</span><strong id="commercial-other-payable-summary" data-other-payable-summary><?= e($otherPayable) ?></strong></div>
        <div class="legacy-highlight legacy-highlight--pink"><span>Profit/Loss</span><strong id="commercial-service-profit" data-service-profit><?= e($profitLoss) ?></strong></div>
    </div>

    <table class="legacy-table legacy-service-table">
        <thead><tr><th>Sr.</th><th>Service Type</th><th>Ticket No. / Ref No.</th><th>Passenger Name</th><th>Sector</th><th>Basic Fare</th><th>Tax All</th><th>Other</th><th>S.P. Total</th><th>Receivable</th><th>Payable</th><th>Profit</th><th>Sts.</th></tr></thead>
        <tbody>
        <?php foreach ($serviceLines as $serviceIndex => $serviceLine): ?>
            <?php $lineTicket = (string) ($serviceLine['ticketNumber'] !== '' ? $serviceLine['ticketNumber'] : $serviceLine['lineNumber']); ?>
            <?php $lineSector = trim((string) ($serviceLine['sectorFrom'] . ($serviceLine['sectorTo'] !== '' ? '-' . $serviceLine['sectorTo'] : ''))); ?>
            <?php $lineSpTotal = (float) ($serviceLine['rowSpTotal'] ?? 0); ?>
            <?php $lineReceivable = (float) ($serviceLine['rowReceivable'] ?? 0); ?>
            <?php $linePayable = (float) ($serviceLine['rowPayable'] ?? 0); ?>
            <?php $lineProfit = (float) ($serviceLine['rowProfit'] ?? $serviceLine['profit'] ?? 0); ?>
            <tr class="<?= $serviceIndex === 0 ? 'is-active' : '' ?>" data-service-row data-service-index="<?= e((string) $serviceIndex) ?>">
                <td><?= e((string) ($serviceIndex + 1)) ?></td>
                <td><?= e(ucwords((string) $serviceLine['type'])) ?></td>
                <td><?= e($lineTicket) ?></td>
                <td><?= e((string) (($serviceLine['passengerName'] ?? '') !== '' ? $serviceLine['passengerName'] : $passengerName)) ?></td>
                <td><?= e($lineSector !== '' ? $lineSector : (string) $serviceLine['remarks']) ?></td>
                <td><?= e($formatMoney((float) ($serviceLine['fare'] ?: $serviceLine['salePrice']))) ?></td>
                <td><?= e($formatMoney($serviceTaxTotal($serviceLine))) ?></td>
                <td><?= e($formatMoney((float) $serviceLine['serviceCharge'])) ?></td>
                <td><?= e($formatMoney($lineSpTotal)) ?></td>
                <td><?= e($formatMoney($lineReceivable)) ?></td>
                <td><?= e($formatMoney($linePayable)) ?></td>
                <td class="profit-cell <?= $lineProfit < 0 ? 'negative' : 'positive' ?>"><?= e($formatMoney($lineProfit)) ?></td>
                <td><?= e(strtoupper(substr((string) ($serviceLine['displayStatus'] ?? $serviceLine['status']), 0, 4))) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

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

    <div class="legacy-bottom-bar">
        <button class="legacy-bottom-button" type="button" data-workspace-action="new-booking">New</button>
        <button class="legacy-bottom-button" type="button" data-workspace-action="edit-booking" data-booking-gated-control <?= $workspaceBooking['id'] > 0 ? '' : 'disabled' ?>>Edit</button>
        <button class="legacy-bottom-button" type="button" data-workspace-action="delete-booking" data-booking-gated-control <?= $workspaceBooking['id'] > 0 ? '' : 'disabled' ?>>Delete</button>
        <button class="legacy-bottom-button" type="button" data-workspace-action="search-booking">Search</button>
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
                    <span>Invoice-level receipts and allocation history. Customer ledger remains separate.</span>
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
                <div class="workspace-feedback workspace-feedback--inline" style="display:block;margin-bottom:12px;">
                    Posted customer receipt financial fields are not editable. To correct amount, currency, allocation, or other financial values, void the receipt and create a new one. Only non-financial metadata may be opened for controlled edit later.
                </div>
                <div class="legacy-modal-grid">
                    <article>
                        <h3>Receipt History</h3>
                        <p class="supplier-settlement-helper">Metadata-only edit is allowed here for payment reference, bank/payment details, and remarks. Financial values such as amount, currency, and allocation stay locked after posting.</p>
                        <table class="legacy-table">
                            <thead><tr><th>Receipt</th><th>Date</th><th>Curr.</th><th>Payment Received</th><th>Applied</th><th>Credit / Return</th><th>Method</th><th>Status</th><th>Print</th><th>Meta Edit</th><th>Void</th><th>Recreate</th></tr></thead>
                            <tbody data-payment-history-receipts-body>
                            <?php foreach ($customerPaymentFoundation['receipts'] ?? [] as $receiptRow): ?>
                                <?php $receiptPrintUrl = $workspaceBooking['id'] > 0 ? url('/workspace/output?booking_id=' . $workspaceBooking['id'] . '&doc=customer_receipt&receipt_id=' . (int) ($receiptRow['id'] ?? 0)) : ''; ?>
                                <?php $receiptStatusRaw = str_replace(' ', '_', mb_strtolower(trim((string) ($receiptRow['statusRaw'] ?? $receiptRow['status'] ?? '')))); ?>
                                <?php $receiptRecreateUrl = url('/workspace?booking_id=' . (int) ($workspaceBooking['id'] ?? 0) . '&recreate_receipt_id=' . (int) ($receiptRow['id'] ?? 0) . '#dock-panel-payments'); ?>
                                <tr><td><?= e((string) $receiptRow['receiptNo']) ?></td><td><?= e((string) $receiptRow['receiptDate']) ?></td><td><?= e((string) $receiptRow['currency']) ?></td><td><?= e($formatMoney((float) $receiptRow['receivedAmount'])) ?></td><td><?= e($formatMoney((float) $receiptRow['allocatedAmount'])) ?></td><td><?= e($formatMoney((float) $receiptRow['unallocatedAmount'])) ?></td><td><?= e(ucwords(str_replace('_', ' ', (string) $receiptRow['paymentMethod']))) ?></td><td><?= e($formatStatusLabel((string) ($receiptRow['statusRaw'] ?? $receiptRow['status'] ?? ''))) ?></td><td><?php if ($receiptPrintUrl !== ''): ?><a href="<?= e($receiptPrintUrl) ?>" target="_blank" rel="noopener">Print</a><?php else: ?>-<?php endif; ?></td><td><form method="post" action="<?= e(url('/workspace/payments/receipts/metadata-save')) ?>" style="display:grid;gap:6px;min-width:190px;"><?= \App\Helpers\Csrf::input() ?><input type="hidden" name="booking_id" value="<?= e((string) ($workspaceBooking['id'] ?? 0)) ?>"><input type="hidden" name="customer_receipt_id" value="<?= e((string) ($receiptRow['id'] ?? 0)) ?>"><input type="text" name="receipt_reference_number" value="<?= e((string) ($receiptRow['referenceNumber'] ?? '')) ?>" placeholder="Payment reference" maxlength="100"><input type="text" name="receipt_bank_card_detail" value="<?= e((string) ($receiptRow['bankCardDetail'] ?? '')) ?>" placeholder="Bank / Payment details" maxlength="190"><input type="text" name="receipt_remarks" value="<?= e((string) ($receiptRow['remarks'] ?? '')) ?>" placeholder="Remarks / note" maxlength="4000"><button class="btn btn-sm" type="submit">Save Notes</button></form></td><td><?php if ($canPostServiceEvents && $receiptStatusRaw !== 'void'): ?><form method="post" action="<?= e(url('/workspace/payments/receipts/void')) ?>" onsubmit="return confirm('Void this receipt and reverse its allocations?');" style="display:grid;gap:6px;min-width:150px;"><?= \App\Helpers\Csrf::input() ?><input type="hidden" name="booking_id" value="<?= e((string) ($workspaceBooking['id'] ?? 0)) ?>"><input type="hidden" name="customer_receipt_id" value="<?= e((string) ($receiptRow['id'] ?? 0)) ?>"><input type="text" name="void_reason" value="" placeholder="Void reason" minlength="5" maxlength="1000" required><button class="btn btn-sm" type="submit">Void</button></form><?php else: ?>-<?php endif; ?></td><td><?php if ($receiptStatusRaw === 'void'): ?><a class="btn btn-sm" href="<?= e($receiptRecreateUrl) ?>">Recreate</a><?php else: ?>-<?php endif; ?></td></tr>
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
                            <?php foreach ($customerPaymentFoundation['allocations'] ?? [] as $allocationRow): ?>
                                <?php
                                $allocationCurrency = (string) ($allocationRow['receivableCurrency'] ?? $allocationRow['currency'] ?? 'PKR');
                                $allocationPassenger = trim((string) ($allocationRow['passengerName'] ?? ''));
                                $allocationReceiptStatusRaw = str_replace(' ', '_', mb_strtolower(trim((string) ($allocationRow['receiptStatusRaw'] ?? ''))));
                                ?>
                                  <tr><td><?= e((string) $allocationRow['allocatedAt']) ?></td><td><?= e((string) $allocationRow['receiptNo']) ?></td><td><?= e((string) (($allocationRow['allocationType'] ?? 'Allocated') === 'Previous Outstanding' ? 'Previous Balance' : (($allocationRow['allocationType'] ?? 'Allocated') === 'Customer Credit / Unallocated' ? 'Customer Credit' : ($allocationRow['allocationType'] ?? 'Allocated')))) ?></td><td><?= e((string) ($allocationRow['bookingReference'] ?? 'N/A')) ?></td><td><?= e((string) (($allocationRow['serviceLineReference'] ?? '') !== '' ? $allocationRow['serviceLineReference'] : 'N/A')) ?></td><td><?= e((string) ($allocationRow['serviceType'] ?? 'Service')) ?></td><td><?= e($allocationPassenger !== '' ? $allocationPassenger : $workspaceBooking['leadTravelerName']) ?></td><td><?= e($allocationCurrency) ?> <?= e($formatMoney((float) ($allocationRow['receivableAmountAllocated'] ?? $allocationRow['allocatedAmount'] ?? 0))) ?></td><td><?php if ($allocationReceiptStatusRaw === 'void'): ?>VOIDED<?php else: ?><?= e($allocationCurrency) ?> <?= e($formatMoney((float) ($allocationRow['remainingAfterAllocation'] ?? 0))) ?><?php endif; ?></td><td><?= e((string) $allocationRow['allocationTrail']) ?></td></tr>
                            <?php endforeach; ?>
                            <?php if (($customerPaymentFoundation['allocations'] ?? []) === []): ?><tr><td colspan="10" class="empty-cell">No allocations posted yet.</td></tr><?php endif; ?>
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
                    <span>Search customer by name, mobile, passport, or family ID, then open the unpaid booking in the stable payment workspace.</span>
                </div>
                <button class="btn btn-sm" type="button" data-customer-dues-close>Close</button>
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
                            <thead><tr><th>Customer</th><th>Passport</th><th>Mobile</th><th>Branch</th><th>Open Balance</th><th>Action</th></tr></thead>
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

    <section class="customer-picker-modal" data-supplier-history-modal hidden aria-hidden="true" data-supplier-history-url="<?= e($supplierHistoryFinderUrl) ?>">
        <div class="customer-picker-modal__backdrop" data-supplier-history-close></div>
        <div class="customer-picker-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="supplier-history-title" style="max-width:1240px;">
            <header class="customer-picker-modal__header">
                <div>
                    <strong id="supplier-history-title">Supplier Payment Finder</strong>
                    <span>Search supplier name, supplier code, or booking reference, then open the booking supplier history even if the payable is already fully settled.</span>
                </div>
                <button class="btn btn-sm" type="button" data-supplier-history-close>Close</button>
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
                            <thead><tr><th>Supplier</th><th>Booking / Invoice</th><th>Booking Date</th><th>Branch</th><th>Curr.</th><th>Total Payable</th><th>Paid</th><th>Balance</th><th>Due Date</th><th>Status</th><th>Action</th></tr></thead>
                            <tbody data-supplier-history-results-body>
                                <tr><td colspan="11" class="empty-cell">Search a supplier or booking to load supplier payment history.</td></tr>
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
                    <label class="station-field span-2"><span>Payment Currency</span><input type="text" value="" readonly data-payment-exchange-payment-currency></label>
                    <label class="station-field span-2"><span>Amount Receiving</span><input type="number" step="0.01" value="" data-payment-exchange-payment-amount></label>
                    <label class="station-field span-2"><span>Rate Date</span><input type="text" value="<?= e(date('Y-m-d')) ?>" readonly data-payment-exchange-rate-date-display></label>
                    <label class="station-field span-6" data-payment-exchange-rate-row hidden><span data-payment-exchange-rate-label>Exchange Rate</span><input type="number" step="0.00000001" value="" data-payment-exchange-rate-input></label>
                </div>
                <div class="workspace-feedback workspace-feedback--inline" data-payment-exchange-rate-help hidden></div>
                <div class="workspace-feedback workspace-feedback--inline" data-payment-exchange-feedback hidden></div>
                <div class="legacy-modal-grid top-gap">
                    <article>
                        <h3>Settlement Summary</h3>
                        <table class="legacy-table">
                            <tbody>
                            <tr><th>Invoice Amount Settled</th><td data-payment-exchange-preview-settled>0.00</td></tr>
                            <tr><th>Payment Amount Used</th><td data-payment-exchange-preview-consumed>0.00</td></tr>
                            <tr><th>Remaining Invoice Balance</th><td data-payment-exchange-preview-target-remaining>0.00</td></tr>
                            <tr><th>Credit / Return</th><td data-payment-exchange-preview-return>0.00</td></tr>
                            <tr><th hidden>Payment Required to Fully Clear Target</th><td hidden data-payment-exchange-preview-required>0.00</td></tr>
                            <tr><th hidden>Remaining Payment Amount</th><td hidden data-payment-exchange-preview-payment-remaining>0.00</td></tr>
                            <tr><th hidden>Auto-Apply to Same Payment Currency Dues</th><td hidden data-payment-exchange-preview-auto-apply>0.00</td></tr>
                            </tbody>
                        </table>
                    </article>
                    <article>
                        <h3>Rules</h3>
                        <div class="workspace-feedback workspace-feedback--inline" style="display:block;">
                            Save Payment remains unchanged for same-currency receipts.
                            This flow settles the current invoice first, then uses any remaining payment only on older dues in the same payment currency.
                        </div>
                    </article>
                </div>
                <div class="station-command-buttons top-gap">
                    <button class="btn btn-primary btn-sm" type="button" data-payment-exchange-confirm>Confirm Settlement</button>
                    <button class="btn btn-sm" type="button" data-payment-exchange-close>Cancel</button>
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
                    <span>Use this screen to pay existing supplier payable balances. For payments made before purchase, use the global Prepaid Supplier Payment option.</span>
                </div>
                <button class="btn btn-sm" type="button" data-supplier-settlement-close>Close</button>
            </header>
            <div class="customer-picker-modal__body">
                <div class="legacy-modal-grid">
                    <article>
                        <h3>Supplier Summary</h3>
                        <p class="supplier-settlement-helper">This popup is only for postpaid supplier settlement. Prepaid supplier payments are managed from the global Prepaid Supplier Payment action.</p>
                        <div class="legacy-highlight-strip">
                            <div class="legacy-highlight legacy-highlight--red"><span>Total Supplier Payable</span><strong><?= e($formatCurrencyTotals($supplierGrossTotals)) ?></strong></div>
                            <div class="legacy-highlight legacy-highlight--orange"><span>Advance Used</span><strong><?= e($formatCurrencyTotals($supplierAdvanceAppliedTotals)) ?></strong></div>
                            <div class="legacy-highlight legacy-highlight--pink"><span>Paid to Supplier</span><strong><?= e($formatCurrencyTotals($supplierPaidTotals)) ?></strong></div>
                            <div class="legacy-highlight legacy-highlight--red"><span>Supplier Balance</span><strong><?= e($formatCurrencyTotals($supplierOutstandingTotals)) ?></strong></div>
                        </div>
                        <table class="legacy-table">
                            <thead><tr><th>Code</th><th>Supplier</th><th>Mode</th><th>Curr.</th><th>Gross</th><th>Paid</th><th>Balance</th></tr></thead>
                            <tbody>
                            <?php foreach (($supplierFoundation['suppliers'] ?? []) as $supplierRow): ?>
                                <tr>
                                    <td><?= e((string) ($supplierRow['code'] ?? '')) ?></td>
                                    <td><?= e((string) ($supplierRow['name'] ?? '')) ?></td>
                                    <td><?= e((string) (($supplierRow['mode'] ?? '') === 'running_balance' ? 'Running Balance' : 'Normal Payable')) ?></td>
                                    <td><?= e((string) ($supplierRow['currency'] ?? '')) ?></td>
                                    <td><?= e($formatMoney((float) ($supplierRow['grossObligation'] ?? 0))) ?></td>
                                    <td><?= e($formatMoney((float) ($supplierRow['totalPaid'] ?? 0))) ?></td>
                                    <td><?= e($formatMoney((float) ($supplierRow['balanceDue'] ?? 0))) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (($supplierFoundation['suppliers'] ?? []) === []): ?><tr><td colspan="7" class="empty-cell">No suppliers linked to this invoice yet.</td></tr><?php endif; ?>
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
                        <p class="supplier-settlement-helper">Posted supplier payment financial fields are not editable. To correct amount, currency, supplier allocation, or other financial values, void the payment and create a new one.</p>
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
                            <input type="hidden" name="supplier_payment_currency" value="" data-simple-postpaid-currency-input>
                            <table class="legacy-table top-gap">
                                <thead><tr><th>Select</th><th>Supplier</th><th>Svc Line</th><th>Curr.</th><th>Due</th><th>Outstanding</th></tr></thead>
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
                                <label class="station-field span-2"><span>Method</span><select name="supplier_payment_method"><?php foreach (['cash' => 'Cash', 'bank_transfer' => 'Bank Transfer', 'debit_card' => 'Debit Card', 'credit_card' => 'Credit Card'] as $paymentMethodValue => $paymentMethodLabel): ?><option value="<?= e($paymentMethodValue) ?>" <?= $supplierDraftMethod === $paymentMethodValue ? 'selected' : '' ?>><?= e($paymentMethodLabel) ?></option><?php endforeach; ?></select></label>
                                <label class="station-field span-2"><span>Reference</span><input type="text" name="supplier_reference_number" value="<?= e($supplierDraftReferenceNumber) ?>"></label>
                                <label class="station-field span-3"><span>Bank / Card Detail</span><input type="text" name="supplier_bank_card_detail" value="<?= e($supplierDraftBankCardDetail) ?>"></label>
                                <label class="station-field span-3"><span>Remarks</span><input type="text" name="supplier_payment_remarks" value="<?= e($supplierDraftRemarks) ?>"></label>
                            </div>
                            <div class="supplier-simple-payment-summary top-gap">
                                <span>Selected outstanding total:</span>
                                <strong data-simple-postpaid-total>0.00</strong>
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
                                    <p class="supplier-settlement-helper">Use this only for a new supplier payment. Once posted, financial fields stay locked and correction must be done through Void plus a new payment.</p>
                                    <?php if ($supplierPaymentRecreateDraft !== null): ?>
                                        <div class="workspace-feedback workspace-feedback--inline" style="display:block;margin-bottom:12px;">
                                            Recreate helper is active for supplier payment <strong><?= e((string) ($supplierPaymentRecreateDraft['paymentNo'] ?? '')) ?></strong>.
                                            This is a fresh draft only; the old payment stays voided and unchanged.
                                        </div>
                                    <?php endif; ?>
                                    <form method="post" action="<?= e(url('/workspace/suppliers/payments/save')) ?>">
                                        <?= \App\Helpers\Csrf::input() ?>
                                        <input type="hidden" name="booking_id" value="<?= e((string) $workspaceBooking['id']) ?>">
                                        <div class="station-form-grid station-form-grid--6 station-form-grid--inline">
                                            <label class="station-field span-2"><span>Supplier</span><input type="text" name="supplier_name" list="service-supplier-options" value="<?= e($supplierDraftName) ?>"></label>
                                            <label class="station-field span-2"><span>Date</span><input type="date" name="supplier_payment_date" value="<?= e($supplierDraftDate) ?>"></label>
                                            <label class="station-field span-2"><span>Currency</span><select name="supplier_payment_currency"><?php foreach (['PKR', 'AED', 'USD'] as $currencyOption): ?><option value="<?= e($currencyOption) ?>" <?= $supplierDraftCurrency === $currencyOption ? 'selected' : '' ?>><?= e($currencyOption) ?></option><?php endforeach; ?></select></label>
                                            <label class="station-field span-2"><span>Amount</span><input type="number" step="0.01" name="supplier_paid_amount" value="<?= e($supplierDraftAmount) ?>"></label>
                                            <label class="station-field span-2"><span>Method</span><select name="supplier_payment_method"><?php foreach (['cash' => 'Cash', 'bank_transfer' => 'Bank Transfer', 'debit_card' => 'Debit Card', 'credit_card' => 'Credit Card'] as $paymentMethodValue => $paymentMethodLabel): ?><option value="<?= e($paymentMethodValue) ?>" <?= $supplierDraftMethod === $paymentMethodValue ? 'selected' : '' ?>><?= e($paymentMethodLabel) ?></option><?php endforeach; ?></select></label>
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
                            </div>
                        </details>
                    </article>
                    <article>
                        <h3>Supplier Payments</h3>
                        <p class="supplier-settlement-helper">Supplier payment history is audit trail. Posted financial values are not edited in place; use Void and then record a replacement payment.</p>
                        <table class="legacy-table">
                            <thead><tr><th>Payment No.</th><th>Supplier</th><th>Date</th><th>Curr.</th><th>Paid</th><th>Allocated</th><th>Open</th><th>Status</th><th>Print</th><th>Meta Edit</th><th>Void</th><th>Recreate</th></tr></thead>
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
                                    <td><?= e($formatStatusLabel((string) ($paymentRow['statusRaw'] ?? $paymentRow['status'] ?? ''))) ?></td>
                                    <td><?php if ($supplierPaymentPrintUrl !== ''): ?><a href="<?= e($supplierPaymentPrintUrl) ?>" target="_blank" rel="noopener">Print</a><?php else: ?>-<?php endif; ?></td>
                                    <td><form method="post" action="<?= e(url('/workspace/suppliers/payments/metadata-save')) ?>" style="display:grid;gap:6px;min-width:190px;"><?= \App\Helpers\Csrf::input() ?><input type="hidden" name="booking_id" value="<?= e((string) ($workspaceBooking['id'] ?? 0)) ?>"><input type="hidden" name="supplier_payment_id" value="<?= e((string) ($paymentRow['id'] ?? 0)) ?>"><input type="text" name="supplier_reference_number" value="<?= e((string) ($paymentRow['referenceNumber'] ?? '')) ?>" placeholder="Reference" maxlength="100"><input type="text" name="supplier_bank_card_detail" value="<?= e((string) ($paymentRow['bankCardDetail'] ?? '')) ?>" placeholder="Bank / Card detail" maxlength="190"><input type="text" name="supplier_payment_remarks" value="<?= e((string) ($paymentRow['remarks'] ?? '')) ?>" placeholder="Remarks" maxlength="4000"><button class="btn btn-sm" type="submit">Save Notes</button></form></td>
                                    <td><?php if ($canPostServiceEvents && $supplierPaymentStatusRaw !== 'void'): ?><form method="post" action="<?= e(url('/workspace/suppliers/payments/void')) ?>" onsubmit="return confirm('Void this supplier payment and reverse its allocations?');" style="display:grid;gap:6px;min-width:150px;"><?= \App\Helpers\Csrf::input() ?><input type="hidden" name="booking_id" value="<?= e((string) ($workspaceBooking['id'] ?? 0)) ?>"><input type="hidden" name="supplier_payment_id" value="<?= e((string) ($paymentRow['id'] ?? 0)) ?>"><input type="text" name="void_reason" value="" placeholder="Void reason" minlength="5" maxlength="1000" required><button class="btn btn-sm" type="submit">Void</button></form><?php else: ?>-<?php endif; ?></td>
                                    <td><?php if ($supplierPaymentStatusRaw === 'void'): ?><a class="btn btn-sm" href="<?= e($supplierPaymentRecreateUrl) ?>">Recreate</a><?php else: ?>-<?php endif; ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (($supplierFoundation['payments'] ?? []) === []): ?><tr><td colspan="12" class="empty-cell">No supplier payments recorded for this invoice yet.</td></tr><?php endif; ?>
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
            <header class="customer-picker-modal__header">
                <div>
                    <strong id="global-prepaid-supplier-title">Prepaid Supplier Payment</strong>
                    <span>Use this when paying a supplier before purchase. This creates supplier advance and can be used later for the same supplier and currency.</span>
                </div>
                <button class="btn btn-sm" type="button" data-global-prepaid-supplier-close>Close</button>
            </header>
            <div class="customer-picker-modal__body">
                <div class="legacy-modal-grid">
                    <article>
                        <h3>Record Global Supplier Advance</h3>
                        <form method="post" action="<?= e(url('/suppliers/advances/save')) ?>">
                            <?= \App\Helpers\Csrf::input() ?>
                            <div class="station-form-grid station-form-grid--6 station-form-grid--inline">
                                <label class="station-field span-2"><span>Branch</span><select name="branch_id"><?php foreach ($branchOptions as $branchRow): ?><option value="<?= e((string) $branchRow['id']) ?>" <?= (int) $branchRow['id'] === $globalPrepaidDefaultBranchId ? 'selected' : '' ?>><?= e((string) $branchRow['name']) ?></option><?php endforeach; ?></select></label>
                                <label class="station-field span-2"><span>Supplier</span><input type="text" name="supplier_name" list="service-supplier-options" value=""></label>
                                <label class="station-field span-2"><span>Currency</span><select name="advance_currency"><?php foreach (['PKR', 'AED', 'USD'] as $currencyOption): ?><option value="<?= e($currencyOption) ?>"><?= e($currencyOption) ?></option><?php endforeach; ?></select></label>
                                <label class="station-field span-2"><span>Payment Date</span><input type="date" name="advance_date" value="<?= e(date('Y-m-d')) ?>"></label>
                                <label class="station-field span-2"><span>Advance Amount</span><input type="number" step="0.01" name="advance_amount" value="0.00"></label>
                                <label class="station-field span-3"><span>Reference</span><input type="text" name="advance_reference_number" value=""></label>
                                <label class="station-field span-3"><span>Remarks</span><input type="text" name="advance_remarks" value=""></label>
                            </div>
                            <div class="station-command-buttons top-gap">
                                <button class="btn btn-primary btn-sm" type="submit">Save Prepaid Supplier Payment</button>
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
        <div class="customer-picker-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="new-customer-title">
            <header class="customer-picker-modal__header">
                <div>
                    <strong id="new-customer-title" data-new-customer-title>New Customer</strong>
                    <span>Create the customer profile first, then continue booking with it.</span>
                </div>
                <button class="btn btn-sm" type="button" data-new-customer-close onclick="return window.workspaceCloseStandaloneEditCustomerModal && window.workspaceCloseStandaloneEditCustomerModal(event)">Close</button>
            </header>
            <div class="customer-picker-modal__body">
                <form method="post" action="<?= e(url('/workspace/travelers/save')) ?>" data-new-customer-form>
                    <?= \App\Helpers\Csrf::input() ?>
                    <input type="hidden" name="booking_id" value="0">
                    <input type="hidden" name="traveler_id" value="0" data-new-customer-traveler-id>
                    <input type="hidden" name="traveler_role" value="lead">
                    <div class="station-form-grid station-form-grid--6 station-form-grid--inline">
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
                    <div class="station-command-buttons top-gap">
                        <button class="btn btn-primary btn-sm" type="submit" data-new-customer-submit>Save Customer</button>
                        <button class="btn btn-sm" type="button" data-new-customer-close onclick="return window.workspaceCloseStandaloneEditCustomerModal && window.workspaceCloseStandaloneEditCustomerModal(event)">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </section>

    <section class="customer-picker-modal" data-customer-picker hidden aria-hidden="true">
        <div class="customer-picker-modal__backdrop" data-customer-picker-close onclick="return window.workspaceCloseStandaloneCustomerModal && window.workspaceCloseStandaloneCustomerModal(event)"></div>
        <div class="customer-picker-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="customer-picker-title">
            <header class="customer-picker-modal__header">
                <div>
                    <strong id="customer-picker-title">Find Customer</strong>
                    <span>Search live and press Enter on the selected row.</span>
                </div>
                <button class="btn btn-sm" type="button" data-customer-picker-close onclick="return window.workspaceCloseStandaloneCustomerModal && window.workspaceCloseStandaloneCustomerModal(event)">Close</button>
            </header>
            <div class="customer-picker-modal__body">
                <label class="station-field station-field--full">
                    <span>Search</span>
                    <input type="text" value="" placeholder="Name / family ID / passport / phone / village / district" data-customer-picker-input oninput="window.workspaceHandleStandaloneCustomerModalInput && window.workspaceHandleStandaloneCustomerModalInput(this)" onkeydown="window.workspaceHandleStandaloneCustomerModalKeydown && window.workspaceHandleStandaloneCustomerModalKeydown(event, this)">
                </label>
                <div class="dense-table-wrap top-gap">
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
                    if (currentServiceId <= 0 || currentTravelerId <= 0) {
                        fillValue(serviceTravelerIdField, customer.id || '');
                        fillValue(servicePassengerField, customer.full_name || '');
                    }
                }

                field.dispatchEvent(new Event('change', { bubbles: true }));
                hidePanel();
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
                    openModal();
                }, true);

                newCustomerButton.addEventListener('click', function (event) {
                    event.preventDefault();
                    event.stopImmediatePropagation();
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

            var shouldSkipField = function (field) {
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

                if (tag === 'textarea') {
                    return true;
                }

                if (tag === 'button' || tag === 'a') {
                    return true;
                }

                if (type === 'hidden' || type === 'button' || type === 'submit' || type === 'reset') {
                    return true;
                }

                var style = window.getComputedStyle(field);

                return style.display === 'none' || style.visibility === 'hidden';
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

            station.addEventListener('keydown', function (event) {
                if (event.key !== 'Enter') {
                    return;
                }

                var target = event.target;

                if (!target || shouldSkipField(target)) {
                    return;
                }

                // Do not interfere with customer search/autocomplete Enter behavior.
                if (
                    target.matches('[data-customer-autocomplete-input]') ||
                    target.matches('[data-customer-picker-input]') ||
                    target.matches('[data-customer-dues-search]')
                ) {
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
                var receivedAmount = parseAmount(amountInput.value);
                var invoiceDueAmount = getInvoiceDueAmount();

                if (receivedAmount <= 0.005 || invoiceDueAmount <= 0.005) {
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
</section>
