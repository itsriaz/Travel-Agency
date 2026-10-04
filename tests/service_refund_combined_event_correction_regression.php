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

$bookingWorkspace = new \App\Services\BookingWorkspaceService($app);
$serviceWorkspace = new \App\Services\ServiceWorkspaceService($app);
$receiptWorkspace = new \App\Services\CustomerReceiptWorkspaceService($app);
$eventRepository = new \App\Repositories\BookingServiceEventRepository($app);
$refundDetailRepository = new \App\Repositories\BookingServiceRefundDetailRepository($app);
$customerRepository = new \App\Repositories\CustomerPaymentRepository($app);
$supplierRepository = new \App\Repositories\SupplierRepository($app);
$accountingRepository = new \App\Repositories\AccountingRepository($app);
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

echo 'Service refund combined-event correction regression' . PHP_EOL;
echo 'Started: ' . date(DATE_ATOM) . PHP_EOL . PHP_EOL;

$db->beginTransaction();

try {
    $customer = $db->query(
        'SELECT id, full_name, mobile FROM travelers WHERE branch_id = 1 ORDER BY id ASC LIMIT 1'
    )->fetch(PDO::FETCH_ASSOC);
    if ($customer === false || (int) ($customer['id'] ?? 0) <= 0) {
        throw new RuntimeException('An existing branch-1 customer is required for the regression fixture.');
    }

    $bookingResult = $bookingWorkspace->saveBooking([
        'branch_id' => 1,
        'booking_status' => 'draft',
        'booking_date' => $today,
        'party_label' => 'Lead Traveler / Booking Party',
        'selected_customer_id' => (int) $customer['id'],
        'lead_traveler_name' => (string) ($customer['full_name'] ?? 'Combined Refund Customer'),
        'contact_mobile' => (string) ($customer['mobile'] ?? '03001234567'),
        'passport_number' => 'CR' . random_int(1000000, 9999999),
        'remarks' => 'Rollback-only combined refund correction fixture',
    ], $actorUserId, $accessibleBranchIds);

    $booking = (array) ($bookingResult['booking'] ?? []);
    $bookingId = (int) ($booking['id'] ?? 0);
    $bookingReference = (string) ($booking['booking_reference'] ?? '');
    $serviceResult = $serviceWorkspace->saveService([
        'booking_id' => $bookingId,
        'service_type' => 'air ticket',
        'service_status' => 'Open',
        'currency' => 'PKR',
        'cost_currency' => 'PKR',
        'supplier_name' => 'Combined Refund Regression Supplier',
        'service_passenger_name' => 'Combined Refund Passenger',
        'sale_price' => '100',
        'purchase_cost' => '100',
        'service_charge' => '10',
        'discount_amount' => '0',
        'taxes' => '0',
        'other_fare' => '0',
        'soto_fare' => '0',
        'spyi_amount' => '0',
        'aq_yr_pk_amount' => '0',
        'yq_amount' => '0',
        'oth_amount' => '0',
        'vat_input' => '0',
        'vat' => '0',
        'commission' => '0',
        'remarks' => 'Combined refund correction service',
        'ticket_pnr' => 'CR' . random_int(1000, 9999),
        'ticket_number' => 'CR-' . random_int(100000, 999999),
        'ticket_airline' => 'PIA',
        'ticket_sector_from' => 'ISB',
        'ticket_sector_to' => 'DXB',
        'ticket_departure_date' => $today,
        'ticket_return_date' => '',
        'ticket_class' => 'Economy',
        'ticket_fare' => '100',
        'ticket_tax' => '0',
        'ticket_vat' => '0',
        'ticket_commission' => '0',
        'ticket_remarks' => 'Combined refund correction service',
    ], $actorUserId, $accessibleBranchIds);

    $service = (array) ($serviceResult['service'] ?? []);
    $serviceId = (int) ($service['id'] ?? 0);
    $lineReference = (string) ($service['line_reference'] ?? '');
    $supplierId = (int) ($service['supplier_id'] ?? 0);
    $obligation = $supplierRepository->findObligationByServiceLine($bookingReference, $lineReference);
    $supplierTreasury = $treasuryRepository->defaultTreasuryAccountForPayment(1, 'PKR', 'cash') ?? [];
    $customerRefundTreasury = $treasuryRepository->defaultTreasuryAccountForPayment(1, 'PKR', 'cash') ?? [];
    if (
        $bookingId <= 0 || $serviceId <= 0 || $supplierId <= 0 || $obligation === null
        || (int) ($supplierTreasury['id'] ?? 0) <= 0 || (int) ($customerRefundTreasury['id'] ?? 0) <= 0
    ) {
        throw new RuntimeException('The combined refund correction fixture could not be initialized.');
    }

    $paymentNo = $supplierRepository->nextSupplierPaymentNumber();
    $supplierPaymentId = $supplierRepository->createSupplierPayment([
        'supplier_id' => $supplierId,
        'branch_id' => 1,
        'booking_reference' => $bookingReference,
        'payment_no' => $paymentNo,
        'payment_date' => $today,
        'currency' => 'PKR',
        'payment_scope' => 'booking',
        'paid_amount' => 100,
        'payment_method' => 'cash',
        'treasury_account_id' => (int) $supplierTreasury['id'],
        'reference_number' => 'COMBINED-REFUND-TEST',
        'charges_amount' => 0,
        'status' => 'paid',
        'exchange_rate_to_booking' => 1,
        'remarks' => 'Rollback-only combined refund supplier payment',
        'actor_user_id' => $actorUserId,
    ]);
    $accountingRepository->postSupplierPaymentRecorded([
        'branch_id' => 1,
        'booking_reference' => $bookingReference,
        'payment_no' => $paymentNo,
        'supplier_payment_id' => $supplierPaymentId,
        'paid_amount' => 100,
        'charges_amount' => 0,
        'payment_method' => 'cash',
        'treasury_account_id' => (int) $supplierTreasury['id'],
        'entry_date' => $today,
        'currency' => 'PKR',
        'actor_user_id' => $actorUserId,
        'narration' => 'Combined refund fixture supplier payment',
    ]);
    $allocationId = $supplierRepository->allocateSupplierPayment(
        $supplierPaymentId,
        (int) ($obligation['id'] ?? 0),
        100,
        null,
        'Combined refund fixture supplier allocation',
        $actorUserId
    );
    $accountingRepository->postSupplierPaymentAllocation([
        'branch_id' => 1,
        'booking_reference' => $bookingReference,
        'source_reference' => $paymentNo . '-ALLOC-' . $allocationId,
        'service_line_reference' => $lineReference,
        'supplier_obligation_id' => (int) ($obligation['id'] ?? 0),
        'supplier_payment_id' => $supplierPaymentId,
        'allocated_amount' => 100,
        'entry_date' => $today,
        'currency' => 'PKR',
        'actor_user_id' => $actorUserId,
        'narration' => 'Combined refund fixture supplier allocation',
    ]);
    $receiptWorkspace->saveReceipt([
        'booking_id' => $bookingId,
        'receipt_date' => $today,
        'receipt_currency' => 'PKR',
        'received_amount' => '110',
        'payment_method' => 'cash',
        'charges_amount' => '0',
        'receipt_status' => 'received',
        'receipt_remarks' => 'Rollback-only combined refund customer receipt',
    ], $actorUserId, $accessibleBranchIds);

    $serviceWorkspace->cancelService([
        'booking_id' => $bookingId,
        'service_id' => $serviceId,
        'cancel_reason' => 'Combined refund regression cancellation',
        'cancel_event_date' => $today,
    ], $actorUserId, $accessibleBranchIds);
    $serviceWorkspace->settleCancellationFinancials([
        'booking_id' => $bookingId,
        'service_id' => $serviceId,
        'settlement_reason' => 'Combined refund regression settlement',
        'settlement_event_date' => $today,
        'customer_penalty_amount' => '40',
        'customer_penalty_currency' => 'PKR',
        'expected_supplier_refund_amount' => '90',
        'expected_supplier_refund_currency' => 'PKR',
        'agency_fee_refund_amount' => '5',
        'supplier_penalty_amount' => '10',
        'supplier_penalty_currency' => 'PKR',
        'settlement_currency' => 'PKR',
    ], $actorUserId, $accessibleBranchIds);

    $cancelEvent = $eventRepository->latestPostedEvent($serviceId, 'cancel') ?? [];
    $cancelPayload = json_decode((string) ($cancelEvent['payload_json'] ?? '{}'), true) ?: [];
    $customerConsumption = $customerRepository->applyRefundAgainstBookingReceiptCredit(
        $bookingReference,
        'PKR',
        30,
        $actorUserId,
        'Legacy combined refund fixture'
    );
    $supplierConsumption = $supplierRepository->consumeRefundableCreditForService(
        $supplierId,
        $bookingReference,
        'PKR',
        30,
        $actorUserId,
        'Legacy combined refund fixture',
        (array) ($cancelPayload['released_supplier_payment_ids'] ?? [])
    );

    $legacyEventId = $eventRepository->createEvent([
        'branch_id' => 1,
        'booking_id' => $bookingId,
        'booking_service_id' => $serviceId,
        'booking_reference' => $bookingReference,
        'service_line_reference' => $lineReference,
        'event_type' => 'refund',
        'event_status' => 'posted',
        'event_date' => $today,
        'currency' => 'PKR',
        'customer_refund_amount' => 30,
        'supplier_refund_amount' => 30,
        'reason' => 'Legacy combined refund fixture',
        'payload_json' => [
            'payment_method' => 'cash',
            'supplier_refund_payment_method' => 'cash',
            'customer_receipt_credit_currency' => 'PKR',
            'customer_receipt_credit_consumption_amount' => (float) ($customerConsumption['applied_amount'] ?? 0),
            'customer_receipt_credit_synthetic' => false,
            'customer_credit_balance_before' => 55,
            'customer_refundable_balance_before' => 55,
            'cash_refund_credit_consumption' => $supplierConsumption,
            'refund_detail' => [
                'refund_payment_method' => 'cash',
                'treasury_account_id' => (int) $customerRefundTreasury['id'],
                'refund_currency' => 'PKR',
                'refund_source_amount' => 30,
                'refund_exchange_rate_to_booking' => 1,
            ],
        ],
        'actor_user_id' => $actorUserId,
    ]);
    $legacyJournalId = $accountingRepository->postServiceRefund([
        'branch_id' => 1,
        'booking_reference' => $bookingReference,
        'service_line_reference' => $lineReference,
        'source_reference' => 'REFUND-EVT-' . $legacyEventId,
        'entry_date' => $today,
        'currency' => 'PKR',
        'payment_method' => 'cash',
        'treasury_account_id' => (int) $customerRefundTreasury['id'],
        'customer_refund_amount' => 30,
        'supplier_refund_amount' => 30,
        'narration' => 'Legacy combined customer and supplier refund',
        'actor_user_id' => $actorUserId,
    ]);
    $eventRepository->attachJournalEntry($legacyEventId, $legacyJournalId);
    $refundDetailRepository->upsertForServiceEvent($legacyEventId, [
        'treasury_account_id' => (int) $customerRefundTreasury['id'],
        'refund_payment_method' => 'cash',
        'refund_currency' => 'PKR',
        'refund_source_amount' => 30,
        'refund_exchange_rate_to_booking' => 1,
        'remarks' => 'Legacy combined refund fixture',
        'actor_user_id' => $actorUserId,
    ]);

    $serviceWorkspace->correctPenaltyRefundWorkflow([
        'booking_id' => $bookingId,
        'service_id' => $serviceId,
        'correction_reason' => 'Correct legacy combined refund safely',
        'correction_event_date' => $today,
        'customer_penalty_amount' => '40',
        'customer_penalty_currency' => 'PKR',
        'customer_penalty_exchange_rate' => '1',
        'expected_supplier_refund_amount' => '90',
        'expected_supplier_refund_currency' => 'PKR',
        'expected_supplier_refund_exchange_rate' => '1',
        'agency_fee_refund_amount' => '5',
        'supplier_penalty_amount' => '10',
        'supplier_penalty_currency' => 'PKR',
        'supplier_penalty_exchange_rate' => '1',
        'settlement_currency' => 'PKR',
        'settlement_exchange_rate' => '1',
        'customer_refund_amount' => '30',
        'customer_refund_source_amount' => '30',
        'customer_refund_currency' => 'PKR',
        'customer_refund_exchange_rate' => '1',
        'refund_payment_method' => 'cash',
        'refund_treasury_account_id' => (int) $customerRefundTreasury['id'],
        'supplier_refund_amount' => '30',
        'supplier_refund_source_amount' => '30',
        'supplier_refund_currency' => 'PKR',
        'supplier_refund_exchange_rate' => '1',
        'supplier_refund_payment_method' => 'cash',
        'supplier_refund_treasury_account_id' => (int) $customerRefundTreasury['id'],
    ], $actorUserId, $accessibleBranchIds);

    $legacyEventStatement = $db->prepare('SELECT * FROM booking_service_events WHERE id = :id');
    $legacyEventStatement->execute(['id' => $legacyEventId]);
    $legacyEvent = $legacyEventStatement->fetch(PDO::FETCH_ASSOC) ?: [];
    $legacyPayload = json_decode((string) ($legacyEvent['payload_json'] ?? '{}'), true) ?: [];
    $postedRefundStatement = $db->prepare(
        'SELECT COUNT(*) AS event_count,
                ROUND(SUM(customer_refund_amount), 2) AS customer_total,
                ROUND(SUM(supplier_refund_amount), 2) AS supplier_total
         FROM booking_service_events
         WHERE booking_service_id = :service_id AND event_type = "refund" AND event_status = "posted"'
    );
    $postedRefundStatement->execute(['service_id' => $serviceId]);
    $postedTotals = $postedRefundStatement->fetch(PDO::FETCH_ASSOC) ?: [];
    $journalCountStatement = $db->prepare(
        'SELECT source_type, COUNT(*) AS row_count
         FROM journal_entries
         WHERE booking_reference = :booking_reference
           AND source_type IN ("service_refund_reversed", "service_refund_component_reversed")
         GROUP BY source_type'
    );
    $journalCountStatement->execute(['booking_reference' => $bookingReference]);
    $journalCounts = [];
    foreach ($journalCountStatement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $journalCounts[(string) ($row['source_type'] ?? '')] = (int) ($row['row_count'] ?? 0);
    }
    $receiptStatement = $db->prepare(
        'SELECT ROUND(SUM(unallocated_amount), 2) AS unallocated_amount,
                ROUND(SUM(returned_amount), 2) AS returned_amount
         FROM customer_receipts
         WHERE booking_reference = :booking_reference AND currency = "PKR" AND status <> "void"'
    );
    $receiptStatement->execute(['booking_reference' => $bookingReference]);
    $receiptTotals = $receiptStatement->fetch(PDO::FETCH_ASSOC) ?: [];
    $supplierPayment = $supplierRepository->findSupplierPaymentById($supplierPaymentId) ?? [];
    $postedRefundRowsStatement = $db->prepare(
        'SELECT id, currency, customer_refund_amount, supplier_refund_amount, journal_entry_id
         FROM booking_service_events
         WHERE booking_service_id = :service_id AND event_type = "refund" AND event_status = "posted"
         ORDER BY id ASC'
    );
    $postedRefundRows = static function () use ($postedRefundRowsStatement, $serviceId): array {
        $postedRefundRowsStatement->execute(['service_id' => $serviceId]);

        return $postedRefundRowsStatement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    };

    $check(
        'Legacy combined event is retired only after both component reversals are recorded',
        (string) ($legacyEvent['event_status'] ?? '') === 'voided'
            && round((float) ($legacyEvent['customer_refund_amount'] ?? -1), 2) === 0.0
            && round((float) ($legacyEvent['supplier_refund_amount'] ?? -1), 2) === 0.0
            && (int) ($legacyPayload['partial_reversals']['customer']['reversal_journal_entry_id'] ?? 0) > 0
            && (int) ($legacyPayload['partial_reversals']['supplier']['reversal_journal_entry_id'] ?? 0) > 0
            && (int) ($legacyEvent['reversal_journal_entry_id'] ?? 0) > 0,
        json_encode(['event' => $legacyEvent, 'partial_reversals' => $legacyPayload['partial_reversals'] ?? []], JSON_UNESCAPED_SLASHES)
    );
    $check(
        'Correction leaves exactly one active customer refund and one active supplier refund',
        (int) ($postedTotals['event_count'] ?? 0) === 2
            && round((float) ($postedTotals['customer_total'] ?? 0), 2) === 30.0
            && round((float) ($postedTotals['supplier_total'] ?? 0), 2) === 30.0,
        json_encode($postedTotals, JSON_UNESCAPED_SLASHES)
    );
    $check(
        'Legacy combined journal is reversed by two components and never by a whole-journal reversal',
        ($journalCounts['service_refund_component_reversed'] ?? 0) === 2
            && ($journalCounts['service_refund_reversed'] ?? 0) === 0,
        json_encode($journalCounts, JSON_UNESCAPED_SLASHES)
    );
    $check(
        'Customer receipt credit is restored and consumed exactly once by the corrected refund',
        round((float) ($receiptTotals['unallocated_amount'] ?? 0), 2) === 25.0
            && round((float) ($receiptTotals['returned_amount'] ?? 0), 2) === 30.0
            && round($accountingRepository->accountNetBalanceForBooking($bookingReference, 'PKR', 'CUSTOMER_CREDIT'), 2) === 25.0,
        json_encode($receiptTotals, JSON_UNESCAPED_SLASHES)
    );
    $check(
        'Supplier refundable payment credit is restored and consumed exactly once by the corrected refund',
        round((float) ($supplierPayment['unallocated_amount'] ?? 0), 2) === 60.0
            && round((float) ($supplierPayment['returned_amount'] ?? 0), 2) === 30.0
            && round($accountingRepository->accountNetBalanceForBooking($bookingReference, 'PKR', 'SUPPLIER_ADVANCES'), 2) === 60.0,
        json_encode($supplierPayment, JSON_UNESCAPED_SLASHES)
    );

    $refundRowsBeforeNoChange = $postedRefundRows();
    $serviceWorkspace->correctPenaltyRefundWorkflow([
        'booking_id' => $bookingId,
        'service_id' => $serviceId,
        'correction_reason' => 'Verify unchanged refunds remain protected',
        'correction_event_date' => $today,
        'customer_penalty_amount' => '40',
        'customer_penalty_currency' => 'PKR',
        'customer_penalty_exchange_rate' => '1',
        'expected_supplier_refund_amount' => '90',
        'expected_supplier_refund_currency' => 'PKR',
        'expected_supplier_refund_exchange_rate' => '1',
        'agency_fee_refund_amount' => '5',
        'supplier_penalty_amount' => '10',
        'supplier_penalty_currency' => 'PKR',
        'supplier_penalty_exchange_rate' => '1',
        'settlement_currency' => 'PKR',
        'settlement_exchange_rate' => '1',
        'customer_refund_amount' => '30',
        'customer_refund_source_amount' => '30',
        'customer_refund_currency' => 'PKR',
        'customer_refund_exchange_rate' => '1',
        'refund_payment_method' => 'cash',
        'refund_treasury_account_id' => (int) $customerRefundTreasury['id'],
        'supplier_refund_amount' => '30',
        'supplier_refund_source_amount' => '30',
        'supplier_refund_currency' => 'PKR',
        'supplier_refund_exchange_rate' => '1',
        'supplier_refund_payment_method' => 'cash',
        'supplier_refund_treasury_account_id' => (int) $customerRefundTreasury['id'],
    ], $actorUserId, $accessibleBranchIds);
    $refundRowsAfterNoChange = $postedRefundRows();
    $check(
        'Saving unchanged separated refunds preserves their event and journal identities',
        $refundRowsAfterNoChange === $refundRowsBeforeNoChange,
        json_encode(['before' => $refundRowsBeforeNoChange, 'after' => $refundRowsAfterNoChange], JSON_UNESCAPED_SLASHES)
    );

    $settlementEditBlocked = false;
    try {
        $serviceWorkspace->correctPenaltyRefundWorkflow([
            'booking_id' => $bookingId,
            'service_id' => $serviceId,
            'correction_reason' => 'Attempt settlement edit with posted refunds',
            'correction_event_date' => $today,
            'customer_penalty_amount' => '41',
            'customer_penalty_currency' => 'PKR',
            'customer_penalty_exchange_rate' => '1',
            'expected_supplier_refund_amount' => '90',
            'expected_supplier_refund_currency' => 'PKR',
            'expected_supplier_refund_exchange_rate' => '1',
            'agency_fee_refund_amount' => '5',
            'supplier_penalty_amount' => '10',
            'supplier_penalty_currency' => 'PKR',
            'supplier_penalty_exchange_rate' => '1',
            'settlement_currency' => 'PKR',
            'settlement_exchange_rate' => '1',
            'customer_refund_amount' => '30',
            'customer_refund_source_amount' => '30',
            'customer_refund_currency' => 'PKR',
            'customer_refund_exchange_rate' => '1',
            'refund_payment_method' => 'cash',
            'refund_treasury_account_id' => (int) $customerRefundTreasury['id'],
            'supplier_refund_amount' => '30',
            'supplier_refund_source_amount' => '30',
            'supplier_refund_currency' => 'PKR',
            'supplier_refund_exchange_rate' => '1',
            'supplier_refund_payment_method' => 'cash',
            'supplier_refund_treasury_account_id' => (int) $customerRefundTreasury['id'],
        ], $actorUserId, $accessibleBranchIds);
    } catch (RuntimeException $exception) {
        $settlementEditBlocked = str_contains($exception->getMessage(), 'Posted refunds are protected');
    }
    $check(
        'Cancellation settlement edits cannot silently reverse posted refunds',
        $settlementEditBlocked && $postedRefundRows() === $refundRowsBeforeNoChange
    );

    $serviceWorkspace->correctPenaltyRefundWorkflow([
        'booking_id' => $bookingId,
        'service_id' => $serviceId,
        'correction_reason' => 'Correct only the customer refund',
        'correction_event_date' => $today,
        'customer_penalty_amount' => '40',
        'customer_penalty_currency' => 'PKR',
        'customer_penalty_exchange_rate' => '1',
        'expected_supplier_refund_amount' => '90',
        'expected_supplier_refund_currency' => 'PKR',
        'expected_supplier_refund_exchange_rate' => '1',
        'agency_fee_refund_amount' => '5',
        'supplier_penalty_amount' => '10',
        'supplier_penalty_currency' => 'PKR',
        'supplier_penalty_exchange_rate' => '1',
        'settlement_currency' => 'PKR',
        'settlement_exchange_rate' => '1',
        'customer_refund_amount' => '20',
        'customer_refund_source_amount' => '20',
        'customer_refund_currency' => 'PKR',
        'customer_refund_exchange_rate' => '1',
        'refund_payment_method' => 'cash',
        'refund_treasury_account_id' => (int) $customerRefundTreasury['id'],
        'supplier_refund_amount' => '30',
        'supplier_refund_source_amount' => '30',
        'supplier_refund_currency' => 'PKR',
        'supplier_refund_exchange_rate' => '1',
        'supplier_refund_payment_method' => 'cash',
        'supplier_refund_treasury_account_id' => (int) $customerRefundTreasury['id'],
    ], $actorUserId, $accessibleBranchIds);
    $refundRowsAfterCustomerOnly = $postedRefundRows();
    $supplierBefore = array_values(array_filter($refundRowsBeforeNoChange, static fn (array $row): bool => (float) ($row['supplier_refund_amount'] ?? 0) > 0.005))[0] ?? [];
    $supplierAfter = array_values(array_filter($refundRowsAfterCustomerOnly, static fn (array $row): bool => (float) ($row['supplier_refund_amount'] ?? 0) > 0.005))[0] ?? [];
    $customerBefore = array_values(array_filter($refundRowsBeforeNoChange, static fn (array $row): bool => (float) ($row['customer_refund_amount'] ?? 0) > 0.005))[0] ?? [];
    $customerAfter = array_values(array_filter($refundRowsAfterCustomerOnly, static fn (array $row): bool => (float) ($row['customer_refund_amount'] ?? 0) > 0.005))[0] ?? [];
    $check(
        'Changing only the customer refund preserves the supplier refund event and journal',
        (int) ($supplierAfter['id'] ?? 0) === (int) ($supplierBefore['id'] ?? -1)
            && (int) ($supplierAfter['journal_entry_id'] ?? 0) === (int) ($supplierBefore['journal_entry_id'] ?? -1)
            && (int) ($customerAfter['id'] ?? 0) !== (int) ($customerBefore['id'] ?? 0)
            && round((float) ($customerAfter['customer_refund_amount'] ?? 0), 2) === 20.0,
        json_encode(['before' => $refundRowsBeforeNoChange, 'after' => $refundRowsAfterCustomerOnly], JSON_UNESCAPED_SLASHES)
    );

    $serviceWorkspace->correctPenaltyRefundWorkflow([
        'booking_id' => $bookingId,
        'service_id' => $serviceId,
        'correction_reason' => 'Verify ordinary separated refund correction',
        'correction_event_date' => $today,
        'customer_penalty_amount' => '40',
        'customer_penalty_currency' => 'PKR',
        'customer_penalty_exchange_rate' => '1',
        'expected_supplier_refund_amount' => '90',
        'expected_supplier_refund_currency' => 'PKR',
        'expected_supplier_refund_exchange_rate' => '1',
        'agency_fee_refund_amount' => '5',
        'supplier_penalty_amount' => '10',
        'supplier_penalty_currency' => 'PKR',
        'supplier_penalty_exchange_rate' => '1',
        'settlement_currency' => 'PKR',
        'settlement_exchange_rate' => '1',
        'customer_refund_amount' => '20',
        'customer_refund_source_amount' => '20',
        'customer_refund_currency' => 'PKR',
        'customer_refund_exchange_rate' => '1',
        'refund_payment_method' => 'cash',
        'refund_treasury_account_id' => (int) $customerRefundTreasury['id'],
        'supplier_refund_amount' => '20',
        'supplier_refund_source_amount' => '20',
        'supplier_refund_currency' => 'PKR',
        'supplier_refund_exchange_rate' => '1',
        'supplier_refund_payment_method' => 'cash',
        'supplier_refund_treasury_account_id' => (int) $customerRefundTreasury['id'],
    ], $actorUserId, $accessibleBranchIds);

    $postedRefundStatement->execute(['service_id' => $serviceId]);
    $separatedCorrectionTotals = $postedRefundStatement->fetch(PDO::FETCH_ASSOC) ?: [];
    $journalCountStatement->execute(['booking_reference' => $bookingReference]);
    $separatedJournalCounts = [];
    foreach ($journalCountStatement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $separatedJournalCounts[(string) ($row['source_type'] ?? '')] = (int) ($row['row_count'] ?? 0);
    }
    $receiptStatement->execute(['booking_reference' => $bookingReference]);
    $separatedReceiptTotals = $receiptStatement->fetch(PDO::FETCH_ASSOC) ?: [];
    $separatedSupplierPayment = $supplierRepository->findSupplierPaymentById($supplierPaymentId) ?? [];

    $check(
        'Ordinary separated refunds keep their existing full-event correction path',
        (int) ($separatedCorrectionTotals['event_count'] ?? 0) === 2
            && round((float) ($separatedCorrectionTotals['customer_total'] ?? 0), 2) === 20.0
            && round((float) ($separatedCorrectionTotals['supplier_total'] ?? 0), 2) === 20.0
            && ($separatedJournalCounts['service_refund_component_reversed'] ?? 0) === 2
            && ($separatedJournalCounts['service_refund_reversed'] ?? 0) === 2,
        json_encode([
            'posted_refunds' => $separatedCorrectionTotals,
            'reversal_journals' => $separatedJournalCounts,
        ], JSON_UNESCAPED_SLASHES)
    );
    $check(
        'Ordinary separated correction preserves exact customer and supplier balances',
        round((float) ($separatedReceiptTotals['unallocated_amount'] ?? 0), 2) === 35.0
            && round((float) ($separatedReceiptTotals['returned_amount'] ?? 0), 2) === 20.0
            && round((float) ($separatedSupplierPayment['unallocated_amount'] ?? 0), 2) === 70.0
            && round((float) ($separatedSupplierPayment['returned_amount'] ?? 0), 2) === 20.0
            && round($accountingRepository->accountNetBalanceForBooking($bookingReference, 'PKR', 'CUSTOMER_CREDIT'), 2) === 35.0
            && round($accountingRepository->accountNetBalanceForBooking($bookingReference, 'PKR', 'SUPPLIER_ADVANCES'), 2) === 70.0,
        json_encode([
            'customer_receipts' => $separatedReceiptTotals,
            'supplier_payment' => $separatedSupplierPayment,
        ], JSON_UNESCAPED_SLASHES)
    );

    $refundRowsBeforeCurrencyCorrection = $postedRefundRows();
    $currencyCorrectionBlocked = false;
    try {
        $serviceWorkspace->correctFinancials([
        'booking_id' => $bookingId,
        'service_id' => $serviceId,
        'corrected_invoice_currency' => 'PKR',
        'corrected_cost_currency' => 'AED',
        'corrected_cost_basis' => '100.00',
        'corrected_final_sale_price' => '110.00',
        'financial_correction_date' => $today,
        'financial_correction_reason' => 'Verify supplier cost currency edits cannot alter posted refunds',
        'financial_correction_note' => 'Rollback-only refund protection regression.',
        ], $actorUserId, $accessibleBranchIds);
    } catch (RuntimeException $exception) {
        $currencyCorrectionBlocked = str_contains($exception->getMessage(), 'posted cancellation settlement');
    }
    $refundRowsAfterCurrencyCorrection = $postedRefundRows();
    $check(
        'Supplier cost currency correction is blocked after settlement and preserves posted refunds',
        $currencyCorrectionBlocked && $refundRowsAfterCurrencyCorrection === $refundRowsBeforeCurrencyCorrection,
        json_encode([
            'before' => $refundRowsBeforeCurrencyCorrection,
            'after' => $refundRowsAfterCurrencyCorrection,
        ], JSON_UNESCAPED_SLASHES)
    );
} catch (Throwable $exception) {
    $check('Combined refund correction completes without exception', false, $exception->getMessage());
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
}

echo PHP_EOL;
echo $failures === []
    ? 'Service refund combined-event correction regression passed.' . PHP_EOL
    : 'Failures:' . PHP_EOL . ' - ' . implode(PHP_EOL . ' - ', $failures) . PHP_EOL;

exit($failures === [] ? 0 : 1);
