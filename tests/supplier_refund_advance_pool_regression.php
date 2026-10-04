<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/Helpers/functions.php';
require BASE_PATH . '/app/Core/bootstrap.php';

$app = \App\Core\App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');
$bookings = new \App\Services\BookingWorkspaceService($app);
$workspace = new \App\Services\ServiceWorkspaceService($app);
$suppliers = new \App\Repositories\SupplierRepository($app);
$events = new \App\Repositories\BookingServiceEventRepository($app);
$accounting = new \App\Repositories\AccountingRepository($app);
$today = date('Y-m-d');
$supplierName = 'Refund Advance Pool Regression ' . random_int(100000, 999999);
$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$createService = static function (string $traveler, float $cost, ?string $supplierOverride = null) use (
    $bookings, $workspace, $supplierName, $today
): array {
    $booking = $bookings->saveBooking([
        'branch_id' => 2, 'booking_status' => 'draft', 'booking_date' => $today,
        'party_label' => 'Lead Traveler / Booking Party',
        'lead_traveler_name' => $traveler,
    ], 1, [1, 2])['booking'];
    $service = $workspace->saveService([
        'booking_id' => (int) $booking['id'], 'service_type' => 'visa',
        'service_status' => 'Open', 'currency' => 'AED', 'cost_currency' => 'AED',
        'supplier_name' => $supplierOverride ?? $supplierName,
        'sale_price' => $cost, 'final_sale_price' => $cost + 100,
        'visa_country' => 'UAE', 'visa_type' => 'Visit Visa',
    ], 1, [1, 2])['service'];
    return [$booking, $service];
};

$db->beginTransaction();
try {
    [$unfundedBooking, $unfundedService] = $createService('Unfunded Supplier Refund', 200,
        'Unfunded Refund Regression ' . random_int(100000, 999999));
    $workspace->cancelService([
        'booking_id' => (int) $unfundedBooking['id'], 'service_id' => (int) $unfundedService['id'],
        'cancel_reason' => 'Unfunded supplier refund regression', 'cancel_event_date' => $today,
    ], 1, [1, 2]);
    $workspace->settleCancellationFinancials([
        'booking_id' => (int) $unfundedBooking['id'], 'service_id' => (int) $unfundedService['id'],
        'settlement_reason' => 'Expected refund is not yet backed by payment',
        'settlement_event_date' => $today, 'customer_penalty_amount' => 0,
        'expected_supplier_refund_amount' => 150, 'expected_supplier_refund_currency' => 'AED',
    ], 1, [1, 2]);
    $unfundedCancel = $events->latestPostedEvent((int) $unfundedService['id'], 'cancel');
    $unfundedPayload = json_decode((string) $unfundedCancel['payload_json'], true, 512, JSON_THROW_ON_ERROR);
    $unfundedState = $workspace->serviceState((int) $unfundedBooking['id'], [1, 2]);
    $unfundedLines = (array) ($unfundedState['services'] ?? []);
    $unfundedLine = array_values(array_filter($unfundedLines,
        static fn (array $line): bool => (int) ($line['id'] ?? 0) === (int) $unfundedService['id']))[0] ?? [];
    $unfundedObligation = $suppliers->findObligationByServiceLine(
        (string) $unfundedBooking['booking_reference'], (string) $unfundedService['line_reference']);
    $assert((int) ($unfundedPayload['supplier_credit_recording_version'] ?? 0) === 1
        && (float) ($unfundedPayload['supplier_credit_recorded_source_amount'] ?? -1) === 0.0
        && (float) ($unfundedPayload['supplier_payable_reduced_source_amount'] ?? -1) === 150.0
        && (float) ($unfundedObligation['net_payable_amount'] ?? -1) === 50.0
        && $suppliers->availableAdvanceBalanceForSupplier((int) $unfundedService['supplier_id'], 2, 'AED') === 0.0
        && (float) ($unfundedLine['supplier_refundable_credit_amount'] ?? -1) === 0.0,
        'Unpaid cancellation must reduce payable to the retained charge without creating advance');
    $unfundedDuplicateBlocked = false;
    try {
        $workspace->refundService([
            'booking_id' => (int) $unfundedBooking['id'], 'service_id' => (int) $unfundedService['id'],
            'refund_reason' => 'Duplicate supplier settlement attempt', 'refund_event_date' => $today,
            'customer_refund_amount' => 0, 'supplier_refund_amount' => 150,
            'supplier_refund_currency' => 'AED', 'supplier_refund_payment_method' => 'supplier_credit',
        ], 1, [1, 2]);
    } catch (RuntimeException $exception) {
        $unfundedDuplicateBlocked = str_contains($exception->getMessage(), 'Do not record it again');
    }
    $assert($unfundedDuplicateBlocked
        && $suppliers->availableAdvanceBalanceForSupplier((int) $unfundedService['supplier_id'], 2, 'AED') === 0.0,
        'Unpaid payable reduction must not be posted again as supplier advance');

    [$partialBooking, $partialService] = $createService('Partly Paid Supplier Refund', 200,
        'Partial Refund Regression ' . random_int(100000, 999999));
    $partialReference = (string) $partialBooking['booking_reference'];
    $partialObligation = $suppliers->findObligationByServiceLine($partialReference,
        (string) $partialService['line_reference']);
    $treasury = new \App\Repositories\TreasuryRepository($app);
    $partialCash = $treasury->defaultTreasuryAccountForPayment(2, 'AED', 'cash');
    $partialPayment = [
        'supplier_id' => (int) $partialService['supplier_id'], 'branch_id' => 2,
        'booking_reference' => $partialReference, 'payment_no' => $suppliers->nextSupplierPaymentNumber(),
        'payment_date' => $today, 'currency' => 'AED', 'payment_scope' => 'booking',
        'paid_amount' => 100, 'payment_method' => 'cash', 'treasury_account_id' => (int) $partialCash['id'],
        'status' => 'paid', 'actor_user_id' => 1,
    ];
    $partialPaymentId = $suppliers->createSupplierPayment($partialPayment);
    $accounting->postSupplierPaymentRecorded(array_merge($partialPayment, [
        'supplier_payment_id' => $partialPaymentId, 'entry_date' => $today,
    ]));
    $partialAllocationId = $suppliers->allocateSupplierPayment($partialPaymentId,
        (int) $partialObligation['id'], 100, 1, 'Partly paid supplier ticket', 1);
    $accounting->postSupplierPaymentAllocation([
        'branch_id' => 2, 'booking_reference' => $partialReference,
        'source_reference' => $partialPayment['payment_no'] . '-ALLOC-' . $partialAllocationId,
        'service_line_reference' => $partialService['line_reference'],
        'supplier_obligation_id' => $partialObligation['id'], 'supplier_payment_id' => $partialPaymentId,
        'allocated_amount' => 100, 'entry_date' => $today, 'currency' => 'AED', 'actor_user_id' => 1,
    ]);
    $cashAfterSupplierPayment = $treasury->currentBalanceForAccountId((int) $partialCash['id']);
    $workspace->cancelService([
        'booking_id' => (int) $partialBooking['id'], 'service_id' => (int) $partialService['id'],
        'cancel_reason' => 'Partly paid supplier refund regression', 'cancel_event_date' => $today,
    ], 1, [1, 2]);
    $workspace->settleCancellationFinancials([
        'booking_id' => (int) $partialBooking['id'], 'service_id' => (int) $partialService['id'],
        'settlement_reason' => 'Supplier keeps fifty and returns the rest',
        'settlement_event_date' => $today, 'customer_penalty_amount' => 0,
        'expected_supplier_refund_amount' => 150, 'expected_supplier_refund_currency' => 'AED',
    ], 1, [1, 2]);
    $partialCancel = $events->latestPostedEvent((int) $partialService['id'], 'cancel');
    $partialPayload = json_decode((string) $partialCancel['payload_json'], true, 512, JSON_THROW_ON_ERROR);
    $partialAdvanceId = (int) ($partialPayload['supplier_credit_converted_advance_ids'][0] ?? 0);
    $partialObligationAfter = $suppliers->findObligationById((int) $partialObligation['id']);
    $assert((float) ($partialPayload['supplier_credit_recorded_source_amount'] ?? -1) === 50.0
        && (float) ($partialPayload['supplier_payable_reduced_source_amount'] ?? -1) === 100.0
        && (float) ($partialObligationAfter['net_payable_amount'] ?? -1) === 0.0
        && (float) ($suppliers->findAdvanceById($partialAdvanceId)['available_amount'] ?? -1) === 50.0
        && $treasury->currentBalanceForAccountId((int) $partialCash['id']) === $cashAfterSupplierPayment,
        'Partly paid cancellation must return only paid excess to advance and clear unpaid payable');
    $reports = new \App\Repositories\ReportRepository($app);
    $assert($reports->payableRefunds([2], null, null, 0, (string) $unfundedBooking['booking_reference']) === []
        && $reports->payableRefunds([2], null, null, 0, $partialReference) === [],
        'Settled unpaid and partly paid cancellations must not appear as supplier refunds still due');
    $ledger = new \App\Services\SupplierLedgerService($app);
    $supplierLedgerNet = static function (array $rows): float {
        return round(array_sum(array_map(static fn (array $row): float =>
            (float) ($row['raw_debit_amount'] ?? 0) - (float) ($row['raw_credit_amount'] ?? 0), $rows)), 2);
    };
    $unfundedLedger = $ledger->report([
        'supplierId' => (int) $unfundedService['supplier_id'], 'currency' => 'AED',
        'bookingReference' => (string) $unfundedBooking['booking_reference'],
    ], [2]);
    $partialLedger = $ledger->report([
        'supplierId' => (int) $partialService['supplier_id'], 'currency' => 'AED',
        'bookingReference' => $partialReference,
    ], [2]);
    $partialSupplierLedger = $ledger->report([
        'supplierId' => (int) $partialService['supplier_id'], 'currency' => 'AED',
        'bookingReference' => '',
    ], [2]);
    $assert($supplierLedgerNet((array) ($unfundedLedger['rows'] ?? [])) === 50.0
        && $supplierLedgerNet((array) ($partialLedger['rows'] ?? [])) === 0.0
        && $supplierLedgerNet((array) ($partialSupplierLedger['rows'] ?? [])) === -50.0,
        'Supplier ledger must show unpaid retained payable 50, close the partly paid booking, and retain supplier-wide advance 50');

    [$existingBooking, $existingService] = $createService('Existing Supplier Payable', 150);
    $existingObligation = $suppliers->findObligationByServiceLine(
        (string) $existingBooking['booking_reference'], (string) $existingService['line_reference']);
    [$sourceBooking, $sourceService] = $createService('Refund Source Booking', 500);
    $sourceReference = (string) $sourceBooking['booking_reference'];
    $sourceObligation = $suppliers->findObligationByServiceLine(
        $sourceReference, (string) $sourceService['line_reference']);
    $assert((int) $sourceObligation['supplier_id'] === (int) $existingObligation['supplier_id'],
        'Fixture payables must share a supplier');

    $paymentNo = $suppliers->nextSupplierPaymentNumber();
    $cash = (new \App\Repositories\TreasuryRepository($app))->defaultTreasuryAccountForPayment(2, 'AED', 'cash');
    $assert(is_array($cash) && (int) ($cash['id'] ?? 0) > 0, 'AED cash funding account is required for the test payment');
    $payment = [
        'supplier_id' => (int) $sourceService['supplier_id'], 'branch_id' => 2,
        'booking_reference' => $sourceReference, 'payment_no' => $paymentNo,
        'payment_date' => $today, 'currency' => 'AED', 'payment_scope' => 'booking',
        'paid_amount' => 500, 'payment_method' => 'cash', 'treasury_account_id' => (int) $cash['id'], 'status' => 'paid',
        'actor_user_id' => 1,
    ];
    $paymentId = $suppliers->createSupplierPayment($payment);
    $accounting->postSupplierPaymentRecorded(array_merge($payment, [
        'supplier_payment_id' => $paymentId, 'entry_date' => $today,
    ]));
    $allocationId = $suppliers->allocateSupplierPayment(
        $paymentId, (int) $sourceObligation['id'], 500, 1, 'Original supplier cost', 1);
    $accounting->postSupplierPaymentAllocation([
        'branch_id' => 2, 'booking_reference' => $sourceReference,
        'source_reference' => $paymentNo . '-ALLOC-' . $allocationId,
        'service_line_reference' => $sourceService['line_reference'],
        'supplier_obligation_id' => $sourceObligation['id'],
        'supplier_payment_id' => $paymentId, 'allocated_amount' => 500,
        'entry_date' => $today, 'currency' => 'AED', 'actor_user_id' => 1,
    ]);

    $workspace->cancelService([
        'booking_id' => (int) $sourceBooking['id'], 'service_id' => (int) $sourceService['id'],
        'cancel_reason' => 'Rollback-only regression', 'cancel_event_date' => $today,
    ], 1, [1, 2]);
    $workspace->settleCancellationFinancials([
        'booking_id' => (int) $sourceBooking['id'], 'service_id' => (int) $sourceService['id'],
        'settlement_reason' => 'Refund retained as supplier advance',
        'settlement_event_date' => $today, 'customer_penalty_amount' => 0,
        'expected_supplier_refund_amount' => 500,
        'expected_supplier_refund_currency' => 'AED',
    ], 1, [1, 2]);
    $posted = $events->latestPostedEvent((int) $sourceService['id'], 'cancel');
    $payload = json_decode((string) $posted['payload_json'], true, 512, JSON_THROW_ON_ERROR);
    $advanceId = (int) ($payload['supplier_credit_converted_advance_ids'][0] ?? 0);
    $advance = $suppliers->findAdvanceById($advanceId);
    $assert($advanceId > 0 && (float) ($advance['available_amount'] ?? 0) === 500.0
        && (float) ($suppliers->findObligationById((int) $existingObligation['id'])['advance_applied_amount'] ?? -1) === 0.0
        && (float) ($payload['supplier_credit_recorded_source_amount'] ?? 0) === 500.0
        && $events->latestPostedEvent((int) $sourceService['id'], 'refund') === null,
        'Cancellation did not place the released payment into reusable credit exactly once');

    $duplicateBlocked = false;
    try {
        $workspace->refundService([
            'booking_id' => (int) $sourceBooking['id'], 'service_id' => (int) $sourceService['id'],
            'refund_reason' => 'Duplicate supplier credit attempt', 'refund_event_date' => $today,
            'customer_refund_amount' => 0, 'supplier_refund_amount' => 500,
            'supplier_refund_currency' => 'AED',
            'supplier_refund_payment_method' => 'supplier_credit',
        ], 1, [1, 2]);
    } catch (RuntimeException $exception) {
        $duplicateBlocked = true;
    }
    $assert($duplicateBlocked && (float) ($suppliers->findAdvanceById($advanceId)['available_amount'] ?? 0) === 500.0,
        'A second supplier refund changed the already recorded credit');

    $workspace->settleCancellationFinancials([
        'booking_id' => (int) $sourceBooking['id'], 'service_id' => (int) $sourceService['id'],
        'settlement_edit_mode' => 1,
        'settlement_reason' => 'Recheck recorded supplier credit without changing the amount',
        'settlement_event_date' => $today, 'customer_penalty_amount' => 0,
        'expected_supplier_refund_amount' => 500,
        'expected_supplier_refund_currency' => 'AED',
    ], 1, [1, 2]);
    $editedCancel = $events->latestPostedEvent((int) $sourceService['id'], 'cancel');
    $editedPayload = json_decode((string) $editedCancel['payload_json'], true, 512, JSON_THROW_ON_ERROR);
    $newAdvanceId = (int) ($editedPayload['supplier_credit_converted_advance_ids'][0] ?? 0);
    $assert($newAdvanceId > 0 && $newAdvanceId !== $advanceId
        && (float) ($suppliers->findAdvanceById($advanceId)['available_amount'] ?? -1) === 0.0
        && (float) ($suppliers->findAdvanceById($newAdvanceId)['available_amount'] ?? -1) === 500.0,
        'Editing the settlement duplicated or lost the supplier credit');
    $advanceId = $newAdvanceId;

    [$futureBooking, $futureService] = $createService('Future Supplier Payable', 200);
    $futureObligation = $suppliers->findObligationByServiceLine(
        (string) $futureBooking['booking_reference'], (string) $futureService['line_reference']);
    $assert((float) ($futureObligation['advance_applied_amount'] ?? 0) === 200.0
        && (float) ($suppliers->findAdvanceById($advanceId)['available_amount'] ?? 0) === 300.0
        && (float) ($suppliers->findObligationById((int) $existingObligation['id'])['advance_applied_amount'] ?? -1) === 0.0,
        'A later booking did not use the shared advance while leaving older payables untouched');

    $workspace->cancelService([
        'booking_id' => (int) $futureBooking['id'], 'service_id' => (int) $futureService['id'],
        'cancel_reason' => 'Advance-funded supplier credit regression', 'cancel_event_date' => $today,
    ], 1, [1, 2]);
    $workspace->settleCancellationFinancials([
        'booking_id' => (int) $futureBooking['id'], 'service_id' => (int) $futureService['id'],
        'settlement_reason' => 'Supplier retained fifty from prepaid ticket',
        'settlement_event_date' => $today, 'customer_penalty_amount' => 0,
        'expected_supplier_refund_amount' => 150,
        'expected_supplier_refund_currency' => 'AED',
    ], 1, [1, 2]);
    $futureCancel = $events->latestPostedEvent((int) $futureService['id'], 'cancel');
    $futurePayload = json_decode((string) $futureCancel['payload_json'], true, 512, JSON_THROW_ON_ERROR);
    $assert((float) ($futurePayload['supplier_credit_recorded_source_amount'] ?? 0) === 150.0
        && (float) ($futurePayload['supplier_credit_converted_payment_amount'] ?? -1) === 0.0
        && (float) ($suppliers->findAdvanceById($advanceId)['available_amount'] ?? 0) === 450.0
        && $events->latestPostedEvent((int) $futureService['id'], 'refund') === null,
        'Advance-funded cancellation did not restore only the refundable portion once');

    $refundReport = (new \App\Repositories\ReportRepository($app))->payableRefunds([2], null, null, 0,
        (string) $futureBooking['booking_reference']);
    $supplierReceivables = (new \App\Repositories\ReportRepository($app))->supplierReceivable([2], null, null);
    $futureReference = (string) $futureBooking['booking_reference'];
    $assert($refundReport === [] && ! in_array($futureReference, array_column($supplierReceivables, 'booking_reference'), true),
        'Refund reports must not show already credited advance as still receivable');

    $ledger = (new \App\Services\SupplierLedgerService($app))->report([
        'supplierId' => (int) $sourceService['supplier_id'],
        'currency' => 'AED',
        'bookingReference' => '',
    ], [2]);
    $ledgerCards = array_column((array) ($ledger['summaryCards'] ?? []), 'value', 'label');
    $assert(($ledgerCards['Supplier Balance / AED'] ?? '') === 'AED 300.00 Advance',
        'Supplier-wide ledger double-counted the moved credit: ' . json_encode($ledgerCards)
            . ' rows=' . json_encode(array_map(static fn (array $row): array => [
                'reference' => $row['booking_reference'] ?? '',
                'entry' => $row['entry_type'] ?? '',
                'debit' => $row['raw_debit_amount'] ?? 0,
                'credit' => $row['raw_credit_amount'] ?? 0,
                'balance' => $row['balance_amount'] ?? '',
            ], (array) ($ledger['rows'] ?? []))));

    echo '[PASS] Retained supplier refund stays in advance pool until a later booking uses it', PHP_EOL;
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
}
