<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

require BASE_PATH . '/app/Helpers/functions.php';
require BASE_PATH . '/app/Core/bootstrap.php';

$app = \App\Core\App::bootstrap(BASE_PATH);
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
    float $serviceCharge,
    string $remarks
): array {
    return [
        'service_type' => 'air ticket',
        'service_status' => 'Open',
        'currency' => 'PKR',
        'supplier_name' => '',
        'service_passenger_name' => $passengerName,
        'sale_price' => number_format($salePrice, 2, '.', ''),
        'purchase_cost' => '0.00',
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
        'ticket_departure_date' => $GLOBALS['today'],
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
            $servicePayload('Regression Cancel Pax', 'RG-CANCEL-001', 'RGCAN1', 100.00, 10.00, 'Lifecycle cancel/refund seed')
        ),
        $actorUserId,
        $accessibleBranchIds
    );
    $reissueServiceSave = $serviceWorkspace->saveService(
        array_merge(
            ['booking_id' => $bookingId],
            $servicePayload('Regression Reissue Pax', 'RG-REISSUE-001', 'RGREI1', 200.00, 20.00, 'Lifecycle reissue seed')
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

    $check(
        'Initial receivables created for both services',
        $initialCancelReceivable !== null && $initialReissueReceivable !== null,
        json_encode([
            'cancel_due' => $initialCancelReceivable['due_amount'] ?? null,
            'reissue_due' => $initialReissueReceivable['due_amount'] ?? null,
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
        'supplier_penalty_amount' => '0.00',
        'settlement_notes' => 'Regression settlement event',
    ], $actorUserId, $accessibleBranchIds);

    $settledReceivable = $receivableRepository->findReceivableByServiceLine($bookingReference, $cancelLineReference);
    $customerCreditAfterSettlement = $accountingRepository->accountNetBalanceForBooking($bookingReference, 'PKR', 'CUSTOMER_CREDIT');
    $latestCancelEvent = $eventRepository->latestPostedEvent($cancelServiceId, 'cancel');
    $check(
        'Cancellation settlement releases customer credit and resyncs receivable truth',
        $settledReceivable !== null
            && round((float) ($settledReceivable['due_amount'] ?? 0), 2) === 40.00
            && round((float) ($settledReceivable['allocated_amount'] ?? 0), 2) === 40.00
            && round((float) ($settledReceivable['outstanding_amount'] ?? 0), 2) === 0.00
            && round($customerCreditAfterSettlement, 2) === 70.00
            && round((float) ($latestCancelEvent['customer_credit_amount'] ?? 0), 2) === 70.00,
        json_encode([
            'receivable' => $settledReceivable,
            'customer_credit_balance' => $customerCreditAfterSettlement,
            'event_customer_credit_amount' => $latestCancelEvent['customer_credit_amount'] ?? null,
        ], JSON_UNESCAPED_SLASHES)
    );

    $serviceWorkspace->refundService([
        'booking_id' => $bookingId,
        'service_id' => $cancelServiceId,
        'refund_reason' => 'Regression refund',
        'refund_event_date' => $today,
        'refund_payment_method' => 'cash',
        'refund_treasury_account_id' => (int) (($treasuryRepository->defaultTreasuryAccountForPayment(1, 'PKR', 'cash') ?? [])['id'] ?? 0),
        'customer_refund_amount' => '30.00',
        'supplier_refund_amount' => '0.00',
        'refund_notes' => 'Regression refund event',
    ], $actorUserId, $accessibleBranchIds);

    $customerCreditAfterRefund = $accountingRepository->accountNetBalanceForBooking($bookingReference, 'PKR', 'CUSTOMER_CREDIT');
    $refundEvents = array_values(array_filter(
        $eventRepository->postedEventsForService($cancelServiceId),
        static fn (array $row): bool => (string) ($row['event_type'] ?? '') === 'refund'
    ));
    $latestRefundEvent = $refundEvents[count($refundEvents) - 1] ?? null;
    $latestRefundDetail = $latestRefundEvent !== null
        ? $refundDetailRepository->findByServiceEventId((int) ($latestRefundEvent['id'] ?? 0))
        : null;

    $check(
        'Refund reduces customer credit, stores treasury source, and records posted refund event',
        $latestRefundEvent !== null
            && round((float) ($latestRefundEvent['customer_refund_amount'] ?? 0), 2) === 30.00
            && (int) ($latestRefundEvent['journal_entry_id'] ?? 0) > 0
            && round($customerCreditAfterRefund, 2) === 40.00
            && $latestRefundDetail !== null
            && (int) ($latestRefundDetail['treasury_account_id'] ?? 0) > 0,
        json_encode([
            'customer_credit_after_refund' => $customerCreditAfterRefund,
            'refund_event' => $latestRefundEvent,
            'refund_detail' => $latestRefundDetail,
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
    $refundRegisterRow = null;
    foreach ($bookingRows as $row) {
        if ((string) ($row['transaction_type'] ?? '') === 'Refund') {
            $refundRegisterRow = $row;
            break;
        }
    }
    $transactionTypes = array_values(array_unique(array_map(
        static fn (array $row): string => (string) ($row['transaction_type'] ?? ''),
        $bookingRows
    )));
    sort($transactionTypes);

    $check(
        'Issue/Reissue/Refund register sees the temporary lifecycle events and refund source detail',
        in_array('Cancel', $transactionTypes, true)
            && in_array('Refund', $transactionTypes, true)
            && in_array('Reissue', $transactionTypes, true)
            && $refundRegisterRow !== null
            && trim((string) ($refundRegisterRow['refund_source_account'] ?? '')) !== '',
        json_encode([
            'booking_reference' => $bookingReference,
            'transaction_types' => $transactionTypes,
            'refund_row' => $refundRegisterRow,
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
    exit(1);
}

echo PHP_EOL . 'Service lifecycle regression passed.' . PHP_EOL;
