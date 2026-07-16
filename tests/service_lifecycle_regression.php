<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';

$app = (isset($app) && $app instanceof \App\Core\App)
    ? $app
    : \App\Core\App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');

$bookingService = new \App\Services\BookingWorkspaceService($app);
$serviceWorkspace = new \App\Services\ServiceWorkspaceService($app);
$receiptWorkspace = new \App\Services\CustomerReceiptWorkspaceService($app);
$serviceRepository = new \App\Repositories\BookingServiceRepository($app);
$eventRepository = new \App\Repositories\BookingServiceEventRepository($app);
$refundDetailRepository = new \App\Repositories\BookingServiceRefundDetailRepository($app);
$receivableRepository = new \App\Repositories\CustomerPaymentRepository($app);
$accountingRepository = new \App\Repositories\AccountingRepository($app);
$reportRepository = new \App\Repositories\ReportRepository($app);
$supplierRepository = new \App\Repositories\SupplierRepository($app);
$treasuryRepository = new \App\Repositories\TreasuryRepository($app);

$actorUserId = 1;
$accessibleBranchIds = [1, 2];
$today = date('Y-m-d');

$failures = [];

$check = static function (string $label, bool $passed, string $details = '') use (&$failures): void {
    echo ($passed ? '[PASS] ' : '[FAIL] ') . $label . ($details !== '' ? ' - ' . $details : '') . PHP_EOL;

    if (! $passed) {
        $failures[] = $label . ($details !== '' ? ': ' . $details : '');
    }
};

$servicePayload = static function (
    string $passengerName,
    string $ticketNumber,
    string $pnr,
    float $salePrice,
    float $purchaseCost,
    float $serviceCharge,
    string $remarks
) use ($today): array {
    return [
        'service_type' => 'air ticket',
        'service_status' => 'Open',
        'currency' => 'PKR',
        'supplier_name' => 'Regression Lifecycle Supplier',
        'service_passenger_name' => $passengerName,
        'sale_price' => number_format($salePrice, 2, '.', ''),
        'purchase_cost' => number_format($purchaseCost, 2, '.', ''),
        'taxes' => '0.00',
        'other_fare' => '0.00',
        'soto_fare' => '0.00',
        'spyi_amount' => '0.00',
        'aq_yr_pk_amount' => '0.00',
        'yq_amount' => '0.00',
        'oth_amount' => '0.00',
        'vat_input' => '0.00',
        'vat' => '0.00',
        'commission' => '0.00',
        'service_charge' => number_format($serviceCharge, 2, '.', ''),
        'discount_amount' => '0.00',
        'remarks' => $remarks,
        'ticket_pnr' => $pnr,
        'ticket_number' => $ticketNumber,
        'ticket_airline' => 'PIA',
        'ticket_sector_from' => 'ISB',
        'ticket_sector_to' => 'DXB',
        'ticket_departure_date' => $today,
        'ticket_return_date' => '',
        'ticket_class' => 'Economy',
        'ticket_fare' => number_format($salePrice, 2, '.', ''),
        'ticket_tax' => '0.00',
        'ticket_vat' => '0.00',
        'ticket_commission' => '0.00',
        'ticket_remarks' => $remarks,
    ];
};

echo 'Service lifecycle regression' . PHP_EOL;
echo 'Started: ' . date(DATE_ATOM) . PHP_EOL . PHP_EOL;

$db->beginTransaction();

try {
    $bookingResult = $bookingService->saveBooking([
        'branch_id' => 1,
        'booking_status' => 'draft',
        'booking_date' => $today,
        'party_label' => 'Lead Traveler / Booking Party',
        'lead_traveler_name' => 'Regression Lifecycle Customer',
        'contact_mobile' => '03001234567',
        'passport_number' => 'RG1234567',
        'remarks' => 'Automated service lifecycle regression',
    ], $actorUserId, $accessibleBranchIds);

    $booking = $bookingResult['booking'] ?? [];
    $bookingId = (int) ($booking['id'] ?? 0);
    $bookingReference = (string) ($booking['booking_reference'] ?? '');

    $check('Temporary booking created for regression', $bookingId > 0 && $bookingReference !== '', $bookingReference);

    $cancelServiceSave = $serviceWorkspace->saveService(
        array_merge(
            ['booking_id' => $bookingId],
            $servicePayload('Regression Cancel Pax', 'RG-CANCEL-001', 'RGCAN1', 100.00, 100.00, 10.00, 'Lifecycle cancel/refund seed')
        ),
        $actorUserId,
        $accessibleBranchIds
    );
    $reissueServiceSave = $serviceWorkspace->saveService(
        array_merge(
            ['booking_id' => $bookingId],
            $servicePayload('Regression Reissue Pax', 'RG-REISSUE-001', 'RGREI1', 200.00, 0.00, 20.00, 'Lifecycle reissue seed')
        ),
        $actorUserId,
        $accessibleBranchIds
    );

    $cancelService = $cancelServiceSave['service'] ?? [];
    $reissueService = $reissueServiceSave['service'] ?? [];
    $cancelServiceId = (int) ($cancelService['id'] ?? 0);
    $reissueServiceId = (int) ($reissueService['id'] ?? 0);
    $cancelLineReference = (string) ($cancelService['line_reference'] ?? '');
    $reissueLineReference = (string) ($reissueService['line_reference'] ?? '');

    $check(
        'Two temporary air-ticket services created',
        $cancelServiceId > 0 && $reissueServiceId > 0 && $cancelLineReference !== '' && $reissueLineReference !== '',
        $cancelLineReference . ' / ' . $reissueLineReference
    );

    $initialCancelReceivable = $receivableRepository->findReceivableByServiceLine($bookingReference, $cancelLineReference);
    $initialReissueReceivable = $receivableRepository->findReceivableByServiceLine($bookingReference, $reissueLineReference);
    $cancelObligation = $supplierRepository->findObligationByServiceLine($bookingReference, $cancelLineReference);

    $check(
        'Initial receivables created for both services',
        $initialCancelReceivable !== null && $initialReissueReceivable !== null,
        json_encode([
            'cancel_due' => $initialCancelReceivable['due_amount'] ?? null,
            'reissue_due' => $initialReissueReceivable['due_amount'] ?? null,
        ], JSON_UNESCAPED_SLASHES)
    );

    $cancelSupplierId = (int) ($cancelService['supplier_id'] ?? 0);
    $defaultSupplierTreasury = $treasuryRepository->defaultTreasuryAccountForPayment(1, 'PKR', 'cash') ?? [];
    $cancelSupplierPaymentNo = $supplierRepository->nextSupplierPaymentNumber();
    $cancelSupplierPaymentId = $supplierRepository->createSupplierPayment([
        'supplier_id' => $cancelSupplierId,
        'branch_id' => 1,
        'booking_reference' => $bookingReference,
        'payment_no' => $cancelSupplierPaymentNo,
        'payment_date' => $today,
        'currency' => 'PKR',
        'payment_scope' => 'booking',
        'paid_amount' => 100.00,
        'payment_method' => 'cash',
        'treasury_account_id' => (int) ($defaultSupplierTreasury['id'] ?? 0),
        'reference_number' => 'RG-SUP-PAY',
        'bank_card_detail' => null,
        'charges_amount' => 0.0,
        'status' => 'paid',
        'exchange_rate_to_booking' => 1.0,
        'remarks' => 'Lifecycle supplier payment seed',
        'actor_user_id' => $actorUserId,
    ]);
    $accountingRepository->postSupplierPaymentRecorded([
        'branch_id' => 1,
        'booking_reference' => $bookingReference,
        'payment_no' => $cancelSupplierPaymentNo,
        'supplier_payment_id' => $cancelSupplierPaymentId,
        'paid_amount' => 100.00,
        'charges_amount' => 0.0,
        'payment_method' => 'cash',
        'treasury_account_id' => (int) ($defaultSupplierTreasury['id'] ?? 0),
        'entry_date' => $today,
        'currency' => 'PKR',
        'actor_user_id' => $actorUserId,
        'narration' => 'Lifecycle supplier payment recorded',
    ]);
    $cancelSupplierAllocationId = $supplierRepository->allocateSupplierPayment(
        $cancelSupplierPaymentId,
        (int) ($cancelObligation['id'] ?? 0),
        100.00,
        null,
        'Lifecycle supplier payment allocation',
        $actorUserId
    );
    $accountingRepository->postSupplierPaymentAllocation([
        'branch_id' => 1,
        'booking_reference' => $bookingReference,
        'source_reference' => $cancelSupplierPaymentNo . '-ALLOC-' . $cancelSupplierAllocationId,
        'service_line_reference' => $cancelLineReference,
        'supplier_obligation_id' => (int) ($cancelObligation['id'] ?? 0),
        'supplier_payment_id' => $cancelSupplierPaymentId,
        'allocated_amount' => 100.00,
        'entry_date' => $today,
        'currency' => 'PKR',
        'actor_user_id' => $actorUserId,
        'narration' => 'Lifecycle supplier payment allocated',
    ]);
    $cancelObligationAfterPayment = $supplierRepository->findObligationByServiceLine($bookingReference, $cancelLineReference);
    $check(
        'Cancellation-seed service supplier payable is fully settled before cancellation',
        $cancelSupplierPaymentId > 0
            && $cancelObligationAfterPayment !== null
            && round((float) ($cancelObligationAfterPayment['net_payable_amount'] ?? 0), 2) === 0.00,
        json_encode([
            'payment_id' => $cancelSupplierPaymentId,
            'payment_no' => $cancelSupplierPaymentNo,
            'obligation' => $cancelObligationAfterPayment,
        ], JSON_UNESCAPED_SLASHES)
    );

    $receiptResult = $receiptWorkspace->saveReceipt([
        'booking_id' => $bookingId,
        'receipt_date' => $today,
        'receipt_currency' => 'PKR',
        'received_amount' => '110.00',
        'payment_method' => 'cash',
        'charges_amount' => '0.00',
        'receipt_status' => 'received',
        'receipt_remarks' => 'Lifecycle regression payment',
    ], $actorUserId, $accessibleBranchIds);

    $receipt = $receiptResult['receipt'] ?? [];
    $receiptId = (int) ($receipt['id'] ?? 0);
    $check('Customer receipt saved for cancellation service', $receiptId > 0, (string) ($receipt['receipt_no'] ?? ''));

    $paidReceivable = $receivableRepository->findReceivableByServiceLine($bookingReference, $cancelLineReference);
    $check(
        'Cancellation-seed service receivable is fully allocated after payment',
        $paidReceivable !== null
            && round((float) ($paidReceivable['allocated_amount'] ?? 0), 2) === 110.00
            && round((float) ($paidReceivable['outstanding_amount'] ?? 0), 2) === 0.00,
        json_encode($paidReceivable, JSON_UNESCAPED_SLASHES)
    );

    $serviceWorkspace->cancelService([
        'booking_id' => $bookingId,
        'service_id' => $cancelServiceId,
        'cancel_reason' => 'Regression cancellation',
        'cancel_event_date' => $today,
        'cancel_notes' => 'Regression cancel event',
    ], $actorUserId, $accessibleBranchIds);

    $cancelledService = $serviceRepository->findServiceById($cancelServiceId);
    $cancelEvents = $eventRepository->postedEventsForService($cancelServiceId);
    $postedCancelEvents = array_values(array_filter(
        $cancelEvents,
        static fn (array $row): bool => (string) ($row['event_type'] ?? '') === 'cancel'
    ));

    $check(
        'Service cancel posts operational cancellation and updates status',
        $cancelledService !== null
            && (string) ($cancelledService['service_status'] ?? '') === 'Cancelled'
            && count($postedCancelEvents) === 1,
        json_encode([
            'service_status' => $cancelledService['service_status'] ?? null,
            'cancel_event_count' => count($postedCancelEvents),
        ], JSON_UNESCAPED_SLASHES)
    );

    $serviceWorkspace->settleCancellationFinancials([
        'booking_id' => $bookingId,
        'service_id' => $cancelServiceId,
        'settlement_reason' => 'Regression cancellation settlement',
        'settlement_event_date' => $today,
        'customer_penalty_amount' => '40.00',
        'expected_supplier_refund_amount' => '90.00',
        'settlement_notes' => 'Regression settlement event',
    ], $actorUserId, $accessibleBranchIds);

    $settledReceivable = $receivableRepository->findReceivableByServiceLine($bookingReference, $cancelLineReference);
    $customerCreditAfterSettlement = $accountingRepository->accountNetBalanceForBooking($bookingReference, 'PKR', 'CUSTOMER_CREDIT');
    $latestCancelEvent = $eventRepository->latestPostedEvent($cancelServiceId, 'cancel');
    $check(
        'Cancellation settlement releases customer credit and resyncs receivable truth',
        $settledReceivable !== null
            && round((float) ($settledReceivable['due_amount'] ?? 0), 2) === 60.00
            && round((float) ($settledReceivable['allocated_amount'] ?? 0), 2) === 60.00
            && round((float) ($settledReceivable['outstanding_amount'] ?? 0), 2) === 0.00
            && round($customerCreditAfterSettlement, 2) === 50.00
            && round((float) ($latestCancelEvent['customer_credit_amount'] ?? 0), 2) === 50.00,
        json_encode([
            'receivable' => $settledReceivable,
            'customer_credit_balance' => $customerCreditAfterSettlement,
            'event_customer_credit_amount' => $latestCancelEvent['customer_credit_amount'] ?? null,
        ], JSON_UNESCAPED_SLASHES)
    );

    $check(
        'Cancellation settlement releases supplier refundable amount for an already-paid supplier',
        $latestCancelEvent !== null
            && round((float) ($latestCancelEvent['supplier_credit_amount'] ?? 0), 2) === 90.00,
        json_encode([
            'event_supplier_credit_amount' => $latestCancelEvent['supplier_credit_amount'] ?? null,
            'event_payload' => $latestCancelEvent['payload_json'] ?? null,
        ], JSON_UNESCAPED_SLASHES)
    );

    $cancelObligationAfterSettlement = $supplierRepository->findObligationByServiceLine($bookingReference, $cancelLineReference);
    $check(
        'Cancellation settlement does not leave supplier penalty payable after supplier was already paid',
        $cancelObligationAfterSettlement !== null
            && round((float) ($cancelObligationAfterSettlement['gross_amount'] ?? 0), 2) === 10.00
            && round((float) ($cancelObligationAfterSettlement['net_payable_amount'] ?? 0), 2) === 0.00,
        json_encode($cancelObligationAfterSettlement, JSON_UNESCAPED_SLASHES)
    );

    $supplierReceivableBeforeRefundRows = $reportRepository->supplierReceivable([1], $today, $today, '', $cancelSupplierId);
    $matchingSupplierReceivableBeforeRefund = null;
    foreach ($supplierReceivableBeforeRefundRows as $row) {
        if (
            (string) ($row['booking_reference'] ?? '') === $bookingReference
            && (string) ($row['service_line_reference'] ?? '') === $cancelLineReference
        ) {
            $matchingSupplierReceivableBeforeRefund = $row;
            break;
        }
    }
    $check(
        'Supplier receivable report shows expected refund even before supplier cash is received',
        $matchingSupplierReceivableBeforeRefund !== null
            && round((float) ($matchingSupplierReceivableBeforeRefund['supplier_credit_amount'] ?? 0), 2) === 90.00
            && round((float) ($matchingSupplierReceivableBeforeRefund['supplier_refund_received'] ?? 0), 2) === 0.00
            && round((float) ($matchingSupplierReceivableBeforeRefund['supplier_receivable_balance'] ?? 0), 2) === 90.00,
        json_encode($matchingSupplierReceivableBeforeRefund, JSON_UNESCAPED_SLASHES)
    );

    $serviceWorkspace->refundService([
        'booking_id' => $bookingId,
        'service_id' => $cancelServiceId,
        'refund_reason' => 'Regression refund',
        'refund_event_date' => $today,
        'refund_payment_method' => 'cash',
        'refund_treasury_account_id' => (int) (($treasuryRepository->defaultTreasuryAccountForPayment(1, 'PKR', 'cash') ?? [])['id'] ?? 0),
        'customer_refund_amount' => '30.00',
        'supplier_refund_amount' => '30.00',
        'refund_notes' => 'Regression refund event',
    ], $actorUserId, $accessibleBranchIds);

    $customerCreditAfterRefund = $accountingRepository->accountNetBalanceForBooking($bookingReference, 'PKR', 'CUSTOMER_CREDIT');
    $refundEvents = array_values(array_filter(
        $eventRepository->postedEventsForService($cancelServiceId),
        static fn (array $row): bool => (string) ($row['event_type'] ?? '') === 'refund'
    ));
    $latestRefundEvent = $refundEvents[count($refundEvents) - 1] ?? null;
    $customerRefundEvent = null;
    $supplierRefundEvent = null;
    foreach ($refundEvents as $refundEventRow) {
        if (round((float) ($refundEventRow['customer_refund_amount'] ?? 0), 2) > 0) {
            $customerRefundEvent = $refundEventRow;
        }
        if (round((float) ($refundEventRow['supplier_refund_amount'] ?? 0), 2) > 0) {
            $supplierRefundEvent = $refundEventRow;
        }
    }
    $refundDetail = $supplierRefundEvent !== null
        ? $refundDetailRepository->findByServiceEventId((int) ($supplierRefundEvent['id'] ?? 0))
        : null;

    $check(
        'Refund reduces customer credit, records supplier refund received, and stores treasury source',
        $customerRefundEvent !== null
            && $supplierRefundEvent !== null
            && round((float) ($customerRefundEvent['customer_refund_amount'] ?? 0), 2) === 30.00
            && round((float) ($supplierRefundEvent['supplier_refund_amount'] ?? 0), 2) === 30.00
            && (int) ($customerRefundEvent['journal_entry_id'] ?? 0) > 0
            && (int) ($supplierRefundEvent['journal_entry_id'] ?? 0) > 0
            && round($customerCreditAfterRefund, 2) === 20.00
            && $refundDetail !== null
            && (int) ($refundDetail['treasury_account_id'] ?? 0) > 0,
        json_encode([
            'customer_credit_after_refund' => $customerCreditAfterRefund,
            'customer_refund_event' => $customerRefundEvent,
            'supplier_refund_event' => $supplierRefundEvent,
            'refund_detail' => $refundDetail,
        ], JSON_UNESCAPED_SLASHES)
    );

    $supplierReceivableRows = $reportRepository->supplierReceivable([1], $today, $today, '', $cancelSupplierId);
    $matchingSupplierReceivable = null;
    foreach ($supplierReceivableRows as $row) {
        if (
            (string) ($row['booking_reference'] ?? '') === $bookingReference
            && (string) ($row['service_line_reference'] ?? '') === $cancelLineReference
        ) {
            $matchingSupplierReceivable = $row;
            break;
        }
    }
    $check(
        'Supplier receivable report shows remaining expected supplier refund after partial receipt',
        $matchingSupplierReceivable !== null
            && round((float) ($matchingSupplierReceivable['supplier_credit_amount'] ?? 0), 2) === 90.00
            && round((float) ($matchingSupplierReceivable['supplier_refund_received'] ?? 0), 2) === 30.00
            && round((float) ($matchingSupplierReceivable['supplier_receivable_balance'] ?? 0), 2) === 60.00,
        json_encode($matchingSupplierReceivable, JSON_UNESCAPED_SLASHES)
    );

    $supplierLedgerRows = $reportRepository->supplierLedger([1], $today, $today, '', '', $cancelSupplierId);
    $matchingSupplierPayableLedgerRow = null;
    $matchingSupplierPaymentLedgerRow = null;
    $matchingSupplierCancellationReversalLedgerRow = null;
    $matchingSupplierPenaltyLedgerRow = null;
    $matchingSupplierRefundLedgerRow = null;
    $matchingSupplierLedgerDebit = 0.0;
    $matchingSupplierLedgerCredit = 0.0;
    foreach ($supplierLedgerRows as $row) {
        if (
            (string) ($row['booking_reference'] ?? '') === $bookingReference
            && (string) ($row['service_line_reference'] ?? '') === $cancelLineReference
        ) {
            $matchingSupplierLedgerDebit += (float) ($row['debit_amount'] ?? 0);
            $matchingSupplierLedgerCredit += (float) ($row['credit_amount'] ?? 0);
        }
        if (
            (string) ($row['booking_reference'] ?? '') === $bookingReference
            && (string) ($row['service_line_reference'] ?? '') === $cancelLineReference
            && (string) ($row['entry_type'] ?? '') === 'Payable Created'
        ) {
            $matchingSupplierPayableLedgerRow = $row;
        }
        if (
            (string) ($row['booking_reference'] ?? '') === $bookingReference
            && (string) ($row['service_line_reference'] ?? '') === $cancelLineReference
            && (string) ($row['entry_type'] ?? '') === 'Supplier Payment'
        ) {
            $matchingSupplierPaymentLedgerRow = $row;
        }
        if (
            (string) ($row['booking_reference'] ?? '') === $bookingReference
            && (string) ($row['service_line_reference'] ?? '') === $cancelLineReference
            && (string) ($row['entry_type'] ?? '') === 'Payable Reversed on Cancellation'
        ) {
            $matchingSupplierCancellationReversalLedgerRow = $row;
        }
        if (
            (string) ($row['booking_reference'] ?? '') === $bookingReference
            && (string) ($row['service_line_reference'] ?? '') === $cancelLineReference
            && (string) ($row['entry_type'] ?? '') === 'Supplier Penalty Retained'
        ) {
            $matchingSupplierPenaltyLedgerRow = $row;
        }
        if (
            (string) ($row['booking_reference'] ?? '') === $bookingReference
            && (string) ($row['service_line_reference'] ?? '') === $cancelLineReference
            && (string) ($row['entry_type'] ?? '') === 'Supplier Refund Received'
        ) {
            $matchingSupplierRefundLedgerRow = $row;
            break;
        }
    }
    $check(
        'Supplier ledger preserves original payable and payment after cancellation',
        $matchingSupplierPayableLedgerRow !== null
            && round((float) ($matchingSupplierPayableLedgerRow['credit_amount'] ?? 0), 2) === 100.00
            && $matchingSupplierPaymentLedgerRow !== null
            && round((float) ($matchingSupplierPaymentLedgerRow['debit_amount'] ?? 0), 2) === 100.00,
        json_encode([
            'payable' => $matchingSupplierPayableLedgerRow,
            'payment' => $matchingSupplierPaymentLedgerRow,
        ], JSON_UNESCAPED_SLASHES)
    );

    $supplierLedgerReportMethod = new ReflectionMethod(\App\Services\ReportService::class, 'supplierLedgerReport');
    $supplierLedgerReportMethod->setAccessible(true);
    $matchingSupplierLedgerRows = array_values(array_filter(
        $supplierLedgerRows,
        static fn (array $row): bool =>
            (string) ($row['booking_reference'] ?? '') === $bookingReference
            && (string) ($row['service_line_reference'] ?? '') === $cancelLineReference
    ));
    [$formattedSupplierLedgerRows, $supplierLedgerCards] = $supplierLedgerReportMethod->invoke(
        new \App\Services\ReportService($app),
        $matchingSupplierLedgerRows
    );
    $supplierLedgerCardValues = array_column($supplierLedgerCards, 'value', 'label');
    $hasSyntheticSupplierTotal = false;
    foreach ($formattedSupplierLedgerRows as $row) {
        if (str_starts_with((string) ($row['supplier_name'] ?? ''), 'TOTAL ')) {
            $hasSyntheticSupplierTotal = true;
            break;
        }
    }
    $check(
        'Supplier ledger summary excludes lifecycle duplication without adding a total row',
        ! $hasSyntheticSupplierTotal
            && ($supplierLedgerCardValues['Supplier Debit / PKR'] ?? '') === 'PKR 100.00'
            && ($supplierLedgerCardValues['Supplier Credit / PKR'] ?? '') === 'PKR 100.00'
            && ($supplierLedgerCardValues['Supplier Balance / PKR'] ?? '') === 'PKR -60.00',
        json_encode([
            'has_synthetic_total' => $hasSyntheticSupplierTotal,
            'cards' => $supplierLedgerCards,
        ], JSON_UNESCAPED_SLASHES)
    );

    $check(
        'Supplier ledger reverses the full original payable before retaining cancellation penalty',
        $matchingSupplierCancellationReversalLedgerRow !== null
            && round((float) ($matchingSupplierCancellationReversalLedgerRow['debit_amount'] ?? 0), 2) === 100.00,
        json_encode($matchingSupplierCancellationReversalLedgerRow, JSON_UNESCAPED_SLASHES)
    );
    $check(
        'Supplier ledger preserves supplier penalty as retained payable after cancellation reversal',
        $matchingSupplierPenaltyLedgerRow !== null
            && round((float) ($matchingSupplierPenaltyLedgerRow['debit_amount'] ?? 0), 2) === 0.00
            && round((float) ($matchingSupplierPenaltyLedgerRow['credit_amount'] ?? 0), 2) === 10.00,
        json_encode($matchingSupplierPenaltyLedgerRow, JSON_UNESCAPED_SLASHES)
    );

    $check(
        'Supplier ledger shows supplier refund received as credit against supplier receivable',
        $matchingSupplierRefundLedgerRow !== null
            && round((float) ($matchingSupplierRefundLedgerRow['debit_amount'] ?? 0), 2) === 0.00
            && round((float) ($matchingSupplierRefundLedgerRow['credit_amount'] ?? 0), 2) === 30.00,
        json_encode($matchingSupplierRefundLedgerRow, JSON_UNESCAPED_SLASHES)
    );

    $check(
        'Supplier ledger closing balance retains only the still-unreceived supplier refund',
        round($matchingSupplierLedgerCredit - $matchingSupplierLedgerDebit, 2) === -60.00,
        json_encode([
            'debit' => round($matchingSupplierLedgerDebit, 2),
            'credit' => round($matchingSupplierLedgerCredit, 2),
            'balance' => round($matchingSupplierLedgerCredit - $matchingSupplierLedgerDebit, 2),
        ], JSON_UNESCAPED_SLASHES)
    );

    $blockedSettlementReverse = false;
    try {
        $serviceWorkspace->reverseCancellationSettlement([
            'booking_id' => $bookingId,
            'service_id' => $cancelServiceId,
            'settlement_reverse_reason' => 'Regression wrong-order attempt',
        ], $actorUserId, $accessibleBranchIds);
    } catch (\RuntimeException $exception) {
        $blockedSettlementReverse = str_contains($exception->getMessage(), 'Reverse the latest posted refund');
    }
    $check(
        'Cancellation settlement reversal is blocked while a refund still exists',
        $blockedSettlementReverse
    );

    $blockedCancelReopen = false;
    try {
        $serviceWorkspace->reopenCancelledService([
            'booking_id' => $bookingId,
            'service_id' => $cancelServiceId,
            'cancel_reopen_reason' => 'Regression wrong-order reopen attempt',
        ], $actorUserId, $accessibleBranchIds);
    } catch (\RuntimeException $exception) {
        $blockedCancelReopen = str_contains($exception->getMessage(), 'Reverse the latest posted refund');
    }
    $check(
        'Cancelled service cannot reopen while a refund still exists',
        $blockedCancelReopen
    );

    $serviceWorkspace->reverseRefundService([
        'booking_id' => $bookingId,
        'service_id' => $cancelServiceId,
        'refund_event_id' => (int) ($latestRefundEvent['id'] ?? 0),
        'refund_reverse_reason' => 'Regression refund reversal',
    ], $actorUserId, $accessibleBranchIds);

    $customerCreditAfterRefundReverse = $accountingRepository->accountNetBalanceForBooking($bookingReference, 'PKR', 'CUSTOMER_CREDIT');
    $supplierReceivableRowsAfterRefundReverse = $reportRepository->supplierReceivable([1], $today, $today, '', $cancelSupplierId);
    $matchingSupplierReceivableAfterRefundReverse = null;
    foreach ($supplierReceivableRowsAfterRefundReverse as $row) {
        if (
            (string) ($row['booking_reference'] ?? '') === $bookingReference
            && (string) ($row['service_line_reference'] ?? '') === $cancelLineReference
        ) {
            $matchingSupplierReceivableAfterRefundReverse = $row;
            break;
        }
    }
    $latestRefundEventAfterReverse = $eventRepository->latestPostedEvent($cancelServiceId, 'refund');
    $check(
        'Refund reversal restores customer credit and restores supplier receivable follow-up',
        round($customerCreditAfterRefundReverse, 2) === 50.00
            && $latestRefundEventAfterReverse === null
            && $matchingSupplierReceivableAfterRefundReverse !== null
            && round((float) ($matchingSupplierReceivableAfterRefundReverse['supplier_receivable_balance'] ?? 0), 2) === 90.00,
        json_encode([
            'customer_credit_after_refund_reverse' => $customerCreditAfterRefundReverse,
            'supplier_receivable_after_refund_reverse' => $matchingSupplierReceivableAfterRefundReverse,
            'latest_refund_event_after_reverse' => $latestRefundEventAfterReverse,
        ], JSON_UNESCAPED_SLASHES)
    );

    $serviceWorkspace->reverseCancellationSettlement([
        'booking_id' => $bookingId,
        'service_id' => $cancelServiceId,
        'settlement_reverse_reason' => 'Regression settlement reversal',
    ], $actorUserId, $accessibleBranchIds);

    $reversedSettlementReceivable = $receivableRepository->findReceivableByServiceLine($bookingReference, $cancelLineReference);
    $reversedSettlementObligation = $supplierRepository->findObligationByServiceLine($bookingReference, $cancelLineReference);
    $customerCreditAfterSettlementReverse = $accountingRepository->accountNetBalanceForBooking($bookingReference, 'PKR', 'CUSTOMER_CREDIT');
    $latestCancelEventAfterSettlementReverse = $eventRepository->latestPostedEvent($cancelServiceId, 'cancel');
    $latestCancelPayloadAfterSettlementReverse = json_decode((string) ($latestCancelEventAfterSettlementReverse['payload_json'] ?? ''), true);
    if (! is_array($latestCancelPayloadAfterSettlementReverse)) {
        $latestCancelPayloadAfterSettlementReverse = [];
    }
    $check(
        'Settlement reversal restores pre-settlement receivable and supplier payable truth',
        $reversedSettlementReceivable !== null
            && round((float) ($reversedSettlementReceivable['due_amount'] ?? 0), 2) === 110.00
            && round((float) ($reversedSettlementReceivable['allocated_amount'] ?? 0), 2) === 110.00
            && round((float) ($reversedSettlementReceivable['outstanding_amount'] ?? 0), 2) === 0.00
            && $reversedSettlementObligation !== null
            && round((float) ($reversedSettlementObligation['gross_amount'] ?? 0), 2) === 100.00
            && round((float) ($reversedSettlementObligation['net_payable_amount'] ?? 0), 2) === 0.00
            && round($customerCreditAfterSettlementReverse, 2) === 0.00
            && (string) ($latestCancelPayloadAfterSettlementReverse['mode'] ?? '') === 'operational_cancel_only',
        json_encode([
            'receivable' => $reversedSettlementReceivable,
            'obligation' => $reversedSettlementObligation,
            'customer_credit_after_settlement_reverse' => $customerCreditAfterSettlementReverse,
            'cancel_payload_after_settlement_reverse' => $latestCancelPayloadAfterSettlementReverse,
        ], JSON_UNESCAPED_SLASHES)
    );

    $blockedCancelReopenWhileSettlementExists = false;
    try {
        $serviceWorkspace->settleCancellationFinancials([
            'booking_id' => $bookingId,
            'service_id' => $cancelServiceId,
            'settlement_reason' => 'Regression repost settlement',
            'settlement_event_date' => $today,
            'customer_penalty_amount' => '40.00',
            'expected_supplier_refund_amount' => '90.00',
            'settlement_notes' => 'Regression repost settlement',
        ], $actorUserId, $accessibleBranchIds);

        $serviceWorkspace->reopenCancelledService([
            'booking_id' => $bookingId,
            'service_id' => $cancelServiceId,
            'cancel_reopen_reason' => 'Regression reopen blocked by settlement',
        ], $actorUserId, $accessibleBranchIds);
    } catch (\RuntimeException $exception) {
        $blockedCancelReopenWhileSettlementExists = str_contains($exception->getMessage(), 'Reverse the cancellation settlement');
    }
    $check(
        'Cancelled service cannot reopen while settlement still exists',
        $blockedCancelReopenWhileSettlementExists
    );

    $serviceWorkspace->reverseCancellationSettlement([
        'booking_id' => $bookingId,
        'service_id' => $cancelServiceId,
        'settlement_reverse_reason' => 'Regression settlement reversal before reopen',
    ], $actorUserId, $accessibleBranchIds);

    $serviceWorkspace->reopenCancelledService([
        'booking_id' => $bookingId,
        'service_id' => $cancelServiceId,
        'cancel_reopen_reason' => 'Regression reopen cancel',
    ], $actorUserId, $accessibleBranchIds);

    $reopenedService = $serviceRepository->findServiceById($cancelServiceId);
    $latestCancelEventAfterReopen = $eventRepository->latestPostedEvent($cancelServiceId, 'cancel');
    $check(
        'Cancel reopen restores the service after refund and settlement are fully reversed',
        $reopenedService !== null
            && (string) ($reopenedService['service_status'] ?? '') === 'Open'
            && $latestCancelEventAfterReopen === null,
        json_encode([
            'service_status' => $reopenedService['service_status'] ?? null,
            'latest_cancel_event_after_reopen' => $latestCancelEventAfterReopen,
        ], JSON_UNESCAPED_SLASHES)
    );

    $lossBookingResult = $bookingService->saveBooking([
        'branch_id' => 1,
        'booking_status' => 'draft',
        'booking_date' => $today,
        'party_label' => 'Lead Traveler / Booking Party',
        'lead_traveler_name' => 'Regression Loss Refund Customer',
        'contact_mobile' => '03007654321',
        'passport_number' => 'RG7654321',
        'remarks' => 'Automated refund pass-through regression',
    ], $actorUserId, $accessibleBranchIds);
    $lossBooking = $lossBookingResult['booking'] ?? [];
    $lossBookingId = (int) ($lossBooking['id'] ?? 0);
    $lossBookingReference = (string) ($lossBooking['booking_reference'] ?? '');
    $lossServiceSave = $serviceWorkspace->saveService(
        array_merge(
            ['booking_id' => $lossBookingId],
            $servicePayload('Regression 600 Refund Pax', 'RG-LOSS-001', 'RGLOSS1', 1188.00, 1188.00, 0.00, 'Loss refund pass-through seed')
        ),
        $actorUserId,
        $accessibleBranchIds
    );
    $lossService = $lossServiceSave['service'] ?? [];
    $lossServiceId = (int) ($lossService['id'] ?? 0);
    $lossLineReference = (string) ($lossService['line_reference'] ?? '');
    $lossObligation = $supplierRepository->findObligationByServiceLine($lossBookingReference, $lossLineReference);
    $lossSupplierPaymentNo = $supplierRepository->nextSupplierPaymentNumber();
    $lossSupplierPaymentId = $supplierRepository->createSupplierPayment([
        'supplier_id' => (int) ($lossService['supplier_id'] ?? 0),
        'branch_id' => 1,
        'booking_reference' => $lossBookingReference,
        'payment_no' => $lossSupplierPaymentNo,
        'payment_date' => $today,
        'currency' => 'PKR',
        'payment_scope' => 'booking',
        'paid_amount' => 1188.00,
        'payment_method' => 'cash',
        'treasury_account_id' => (int) ($defaultSupplierTreasury['id'] ?? 0),
        'reference_number' => 'RG-LOSS-SUP-PAY',
        'bank_card_detail' => null,
        'charges_amount' => 0.0,
        'status' => 'paid',
        'exchange_rate_to_booking' => 1.0,
        'remarks' => 'Loss refund supplier payment seed',
        'actor_user_id' => $actorUserId,
    ]);
    $accountingRepository->postSupplierPaymentRecorded([
        'branch_id' => 1,
        'booking_reference' => $lossBookingReference,
        'payment_no' => $lossSupplierPaymentNo,
        'supplier_payment_id' => $lossSupplierPaymentId,
        'paid_amount' => 1188.00,
        'charges_amount' => 0.0,
        'payment_method' => 'cash',
        'treasury_account_id' => (int) ($defaultSupplierTreasury['id'] ?? 0),
        'entry_date' => $today,
        'currency' => 'PKR',
        'actor_user_id' => $actorUserId,
        'narration' => 'Loss refund supplier payment recorded',
    ]);
    $lossSupplierAllocationId = $supplierRepository->allocateSupplierPayment(
        $lossSupplierPaymentId,
        (int) ($lossObligation['id'] ?? 0),
        1188.00,
        null,
        'Loss refund supplier payment allocation',
        $actorUserId
    );
    $accountingRepository->postSupplierPaymentAllocation([
        'branch_id' => 1,
        'booking_reference' => $lossBookingReference,
        'source_reference' => $lossSupplierPaymentNo . '-ALLOC-' . $lossSupplierAllocationId,
        'service_line_reference' => $lossLineReference,
        'supplier_obligation_id' => (int) ($lossObligation['id'] ?? 0),
        'supplier_payment_id' => $lossSupplierPaymentId,
        'allocated_amount' => 1188.00,
        'entry_date' => $today,
        'currency' => 'PKR',
        'actor_user_id' => $actorUserId,
        'narration' => 'Loss refund supplier payment allocated',
    ]);
    $receiptWorkspace->saveReceipt([
        'booking_id' => $lossBookingId,
        'receipt_date' => $today,
        'receipt_currency' => 'PKR',
        'received_amount' => '1180.00',
        'payment_method' => 'cash',
        'charges_amount' => '0.00',
        'receipt_status' => 'received',
        'receipt_remarks' => 'Loss refund customer payment',
    ], $actorUserId, $accessibleBranchIds);
    $serviceWorkspace->cancelService([
        'booking_id' => $lossBookingId,
        'service_id' => $lossServiceId,
        'cancel_reason' => 'Regression loss cancellation',
        'cancel_event_date' => $today,
        'cancel_notes' => 'Regression loss cancel event',
    ], $actorUserId, $accessibleBranchIds);
    $serviceWorkspace->settleCancellationFinancials([
        'booking_id' => $lossBookingId,
        'service_id' => $lossServiceId,
        'settlement_reason' => 'Regression 600 refund settlement',
        'settlement_event_date' => $today,
        'customer_penalty_amount' => '53.00',
        'expected_supplier_refund_amount' => '653.00',
        'settlement_notes' => 'Regression should release 600 customer credit',
    ], $actorUserId, $accessibleBranchIds);

    $lossSettledReceivable = $receivableRepository->findReceivableByServiceLine($lossBookingReference, $lossLineReference);
    $lossLatestCancelEvent = $eventRepository->latestPostedEvent($lossServiceId, 'cancel');
    $lossSupplierReceivableRows = $reportRepository->supplierReceivable([1], $today, $today, '', (int) ($lossService['supplier_id'] ?? 0));
    $lossSupplierReceivable = null;
    foreach ($lossSupplierReceivableRows as $row) {
        if (
            (string) ($row['booking_reference'] ?? '') === $lossBookingReference
            && (string) ($row['service_line_reference'] ?? '') === $lossLineReference
        ) {
            $lossSupplierReceivable = $row;
            break;
        }
    }
    $check(
        'Cancellation refund pass-through does not reduce customer refund by old sale loss',
        $lossSettledReceivable !== null
            && $lossLatestCancelEvent !== null
            && round((float) ($lossSettledReceivable['due_amount'] ?? 0), 2) === 580.00
            && round((float) ($lossSettledReceivable['allocated_amount'] ?? 0), 2) === 580.00
            && round((float) ($lossLatestCancelEvent['customer_credit_amount'] ?? 0), 2) === 600.00
            && round((float) ($lossLatestCancelEvent['supplier_credit_amount'] ?? 0), 2) === 653.00
            && $lossSupplierReceivable !== null
            && round((float) ($lossSupplierReceivable['supplier_receivable_balance'] ?? 0), 2) === 653.00,
        json_encode([
            'receivable' => $lossSettledReceivable,
            'cancel_event' => $lossLatestCancelEvent,
            'supplier_receivable' => $lossSupplierReceivable,
        ], JSON_UNESCAPED_SLASHES)
    );

    $serviceWorkspace->refundService([
        'booking_id' => $lossBookingId,
        'service_id' => $lossServiceId,
        'refund_reason' => 'Regression later supplier refund received',
        'refund_event_date' => $today,
        'customer_refund_amount' => '600.00',
        'supplier_refund_amount' => '653.00',
        'refund_payment_method' => 'cash',
        'refund_treasury_account_id' => (int) (($treasuryRepository->defaultTreasuryAccountForPayment(1, 'PKR', 'cash') ?? [])['id'] ?? 0),
        'refund_notes' => 'Regression clears customer and supplier refund follow-up',
    ], $actorUserId, $accessibleBranchIds);

    $lossSupplierReceivableRowsAfterRefund = $reportRepository->supplierReceivable([1], $today, $today, '', (int) ($lossService['supplier_id'] ?? 0));
    $lossSupplierReceivableAfterRefund = null;
    foreach ($lossSupplierReceivableRowsAfterRefund as $row) {
        if (
            (string) ($row['booking_reference'] ?? '') === $lossBookingReference
            && (string) ($row['service_line_reference'] ?? '') === $lossLineReference
        ) {
            $lossSupplierReceivableAfterRefund = $row;
            break;
        }
    }
    $lossCustomerCreditAfterRefund = (new \App\Repositories\AccountingRepository($app))->accountNetBalanceForBooking(
        $lossBookingReference,
        'PKR',
        'CUSTOMER_CREDIT'
    );
    $check(
        'Later supplier refund received clears expected supplier receivable without requiring advance credit',
        round((float) $lossCustomerCreditAfterRefund, 2) === 0.00
            && (
                $lossSupplierReceivableAfterRefund === null
                || round((float) ($lossSupplierReceivableAfterRefund['supplier_receivable_balance'] ?? 0), 2) === 0.00
            ),
        json_encode([
            'customer_credit_after_refund' => $lossCustomerCreditAfterRefund,
            'supplier_receivable_after_refund' => $lossSupplierReceivableAfterRefund,
        ], JSON_UNESCAPED_SLASHES)
    );

    $serviceWorkspace->reissueService([
        'booking_id' => $bookingId,
        'service_id' => $reissueServiceId,
        'reissue_reason' => 'Regression reissue',
        'reissue_event_date' => $today,
        'new_ticket_number' => 'RG-REISSUE-002',
        'new_pnr' => 'RGREI2',
        'fare_difference_amount' => '15.00',
        'reissue_service_fee_amount' => '5.00',
        'supplier_cost_difference_amount' => '0.00',
        'reissue_notes' => 'Regression reissue event',
    ], $actorUserId, $accessibleBranchIds);

    $reissuedService = $serviceRepository->findServiceById($reissueServiceId);
    $reissuedReceivable = $receivableRepository->findReceivableByServiceLine($bookingReference, $reissueLineReference);
    $reissueEvents = array_values(array_filter(
        $eventRepository->postedEventsForService($reissueServiceId),
        static fn (array $row): bool => (string) ($row['event_type'] ?? '') === 'reissue'
    ));
    $latestReissueEvent = $reissueEvents[count($reissueEvents) - 1] ?? null;

    $check(
        'Reissue updates ticket details and increases receivable by fare/service difference',
        $reissuedService !== null
            && (string) ($reissuedService['ticket_number'] ?? '') === 'RG-REISSUE-002'
            && (string) ($reissuedService['pnr'] ?? '') === 'RGREI2'
            && $reissuedReceivable !== null
            && round((float) ($reissuedReceivable['due_amount'] ?? 0), 2) === 240.00
            && $latestReissueEvent !== null
            && round((float) ($latestReissueEvent['fare_difference_amount'] ?? 0), 2) === 15.00
            && round((float) ($latestReissueEvent['service_fee_amount'] ?? 0), 2) === 5.00,
        json_encode([
            'service_ticket_number' => $reissuedService['ticket_number'] ?? null,
            'service_pnr' => $reissuedService['pnr'] ?? null,
            'receivable_due' => $reissuedReceivable['due_amount'] ?? null,
            'reissue_event' => $latestReissueEvent,
        ], JSON_UNESCAPED_SLASHES)
    );

    $registerRows = $reportRepository->issueReissueRefundRegister([1], $today, $today);
    $bookingRows = array_values(array_filter(
        $registerRows,
        static fn (array $row): bool => (string) ($row['booking_reference'] ?? '') === $bookingReference
    ));
    $transactionTypes = array_values(array_unique(array_map(
        static fn (array $row): string => (string) ($row['transaction_type'] ?? ''),
        $bookingRows
    )));
    sort($transactionTypes);

    $check(
        'Issue/Reissue/Refund register reflects final corrected state after reversal and reopen',
        in_array('Issue', $transactionTypes, true)
            && in_array('Reissue', $transactionTypes, true)
            && ! in_array('Cancel', $transactionTypes, true)
            && ! in_array('Refund', $transactionTypes, true),
        json_encode([
            'booking_reference' => $bookingReference,
            'transaction_types' => $transactionTypes,
            'rows' => $bookingRows,
        ], JSON_UNESCAPED_SLASHES)
    );
} catch (\Throwable $exception) {
    $check('Service lifecycle regression completed without unexpected exception', false, $exception::class . ': ' . $exception->getMessage());
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
}

if ($failures !== []) {
    echo PHP_EOL . 'Service lifecycle regression failed:' . PHP_EOL;
    foreach ($failures as $failure) {
        echo ' - ' . $failure . PHP_EOL;
    }
    return 1;
}

echo PHP_EOL . 'Service lifecycle regression passed.' . PHP_EOL;
return 0;
