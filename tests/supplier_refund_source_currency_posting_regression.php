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
$workspace = new \App\Services\ServiceWorkspaceService($app);
$suppliers = new \App\Repositories\SupplierRepository($app);
$events = new \App\Repositories\BookingServiceEventRepository($app);
$accounting = new \App\Repositories\AccountingRepository($app);
$treasury = new \App\Repositories\TreasuryRepository($app);
$today = date('Y-m-d');
$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

$db->beginTransaction();
try {
    $booking = (new \App\Services\BookingWorkspaceService($app))->saveBooking([
        'branch_id' => 2, 'booking_status' => 'draft', 'booking_date' => $today,
        'party_label' => 'Lead Traveler / Booking Party',
        'lead_traveler_name' => 'Supplier Source Currency Regression',
        'remarks' => 'Rollback-only fixture',
    ], 1, [1, 2]);
    $bookingId = (int) $booking['booking']['id'];
    $reference = (string) $booking['booking']['booking_reference'];
    $service = $workspace->saveService([
        'booking_id' => $bookingId, 'service_type' => 'visa', 'service_status' => 'Open',
        'currency' => 'AED', 'cost_currency' => 'AED',
        'supplier_name' => 'Supplier Source Regression ' . random_int(100000, 999999),
        'sale_price' => 653, 'final_sale_price' => 800,
        'visa_country' => 'UAE', 'visa_type' => 'Visit Visa',
        'remarks' => 'Rollback-only fixture',
    ], 1, [1, 2])['service'];
    $serviceId = (int) $service['id'];
    $obligation = $suppliers->findObligationByServiceLine($reference, (string) $service['line_reference']);
    $cash = $treasury->defaultTreasuryAccountForPayment(2, 'AED', 'cash');
    $paymentNo = $suppliers->nextSupplierPaymentNumber();
    $payment = [
        'supplier_id' => (int) $service['supplier_id'], 'branch_id' => 2,
        'booking_reference' => $reference, 'payment_no' => $paymentNo, 'payment_date' => $today,
        'currency' => 'AED', 'payment_scope' => 'booking', 'paid_amount' => 653,
        'payment_method' => 'cash', 'treasury_account_id' => (int) $cash['id'],
        'charges_amount' => 0, 'status' => 'paid', 'actor_user_id' => 1,
    ];
    $paymentId = $suppliers->createSupplierPayment($payment);
    $accounting->postSupplierPaymentRecorded(array_merge($payment, [
        'supplier_payment_id' => $paymentId, 'entry_date' => $today,
    ]));
    $allocationId = $suppliers->allocateSupplierPayment($paymentId, (int) $obligation['id'], 653, 1,
        'Original supplier cost', 1);
    $accounting->postSupplierPaymentAllocation([
        'branch_id' => 2, 'booking_reference' => $reference,
        'source_reference' => $paymentNo . '-ALLOC-' . $allocationId,
        'service_line_reference' => $service['line_reference'],
        'supplier_obligation_id' => $obligation['id'], 'supplier_payment_id' => $paymentId,
        'allocated_amount' => 653, 'entry_date' => $today, 'currency' => 'AED', 'actor_user_id' => 1,
    ]);

    $workspace->cancelService([
        'booking_id' => $bookingId, 'service_id' => $serviceId,
        'cancel_reason' => 'Regression cancellation', 'cancel_event_date' => $today,
    ], 1, [1, 2]);
    $workspace->settleCancellationFinancials([
        'booking_id' => $bookingId, 'service_id' => $serviceId,
        'settlement_reason' => 'Regression mixed-currency expectation',
        'settlement_event_date' => $today, 'customer_penalty_amount' => 0,
        'expected_supplier_refund_amount' => 50242,
        'expected_supplier_refund_currency' => 'PKR',
        'expected_supplier_refund_exchange_rate' => round(653 / 50242, 8),
        'expected_supplier_refund_equivalent_amount' => 653,
    ], 1, [1, 2]);

    $refundInput = [
        'booking_id' => $bookingId, 'service_id' => $serviceId,
        'refund_reason' => 'Regression supplier credit in PKR', 'refund_event_date' => $today,
        'customer_refund_amount' => 0, 'supplier_refund_amount' => 50242,
        'supplier_refund_amount_basis' => 'expected_source',
        'supplier_refund_currency' => 'PKR',
        'supplier_refund_payment_method' => 'supplier_credit',
    ];
    $cancelSnapshot = json_decode((string) $events->latestPostedEvent($serviceId, 'cancel')['payload_json'], true, 512, JSON_THROW_ON_ERROR);
    $deriveExpectedSource = new ReflectionMethod(\App\Services\ServiceWorkspaceService::class, 'normalizeExpectedSourceSupplierRefundInput');
    $partial = $deriveExpectedSource->invoke($workspace, array_replace($refundInput, [
        'supplier_refund_amount' => 25000, 'supplier_refund_equivalent_amount' => 325,
    ]), $cancelSnapshot, 'AED', 'AED');
    $assert((float) $partial['supplier_refund_amount'] === 325.0
        && (float) $partial['supplier_refund_source_amount'] === 25000.0,
        'Partial PKR supplier recovery did not retain the manually confirmed AED equivalent');
    $missingEquivalent = $cancelSnapshot;
    unset(
        $missingEquivalent['expected_supplier_refund_booking_amount'],
        $missingEquivalent['expected_supplier_refund_equivalent_amount'],
        $missingEquivalent['expected_supplier_refund_equivalent_currency']
    );
    try {
        $deriveExpectedSource->invoke($workspace, $refundInput, $missingEquivalent, 'AED', 'AED');
        throw new RuntimeException('Missing saved equivalent was accepted');
    } catch (RuntimeException $error) {
        $assert(str_contains($error->getMessage(), 'no verified supplier refund equivalent'), $error->getMessage());
    }
    $before = (int) $db->query('SELECT COUNT(*) FROM booking_service_events')->fetchColumn();
    try {
        $workspace->refundService(array_replace($refundInput, ['supplier_refund_amount_basis' => 'expected_source_derived']), 1, [1, 2]);
        throw new RuntimeException('Client-supplied derived basis was accepted');
    } catch (RuntimeException $error) {
        $assert(str_contains($error->getMessage(), 'Invalid supplier refund amount basis'), $error->getMessage());
    }
    $db->exec('SAVEPOINT supplier_actual_differs_from_expected');
    try {
        $workspace->refundService(array_replace($refundInput, [
            'supplier_refund_currency' => 'AED', 'supplier_refund_amount' => 653,
        ]), 1, [1, 2]);
        $assert((string) ($events->latestPostedEvent($serviceId, 'refund')['currency'] ?? '') === 'AED',
            'Actual supplier refund currency may differ from saved expected currency');
    } finally {
        $db->exec('ROLLBACK TO SAVEPOINT supplier_actual_differs_from_expected');
    }
    $assert((int) $db->query('SELECT COUNT(*) FROM booking_service_events')->fetchColumn() === $before,
        'Refund currency trial left no posted event');
    try {
        $workspace->refundService(array_replace($refundInput, ['supplier_refund_amount' => 50243]), 1, [1, 2]);
        throw new RuntimeException('Supplier source amount above the saved expectation was accepted');
    } catch (RuntimeException $error) {
        $assert(str_contains($error->getMessage(), 'exceeds the saved expected amount'), $error->getMessage());
    }
    try {
        $workspace->refundService(array_replace($refundInput, [
            'supplier_refund_payment_method' => 'debit_card',
        ]), 1, [1, 2]);
        throw new RuntimeException('Cross-currency supplier money was posted without a treasury FX journal');
    } catch (RuntimeException $error) {
        $assert(str_contains($error->getMessage(), 'requires a cash or bank account'), $error->getMessage());
    }
    $assert((int) $db->query('SELECT COUNT(*) FROM booking_service_events')->fetchColumn() === $before,
        'Rejected cross-currency supplier money wrote an event');
    $pkrFxCashId = $treasury->saveAccount([
        'branch_id' => 2, 'account_type' => 'cash', 'account_name' => 'FX ' . random_int(100000, 999999) . ' PKR refund fixture',
        'currency' => 'PKR', 'is_active' => 1, 'is_default' => 0, 'opening_balance' => 0,
        'created_by_user_id' => 1,
    ], [1, 2]);
    $db->exec('SAVEPOINT supplier_cross_currency_cash_trial');
    try {
        $workspace->refundService(array_replace($refundInput, [
            'supplier_refund_payment_method' => 'cash',
            'supplier_refund_treasury_account_id' => $pkrFxCashId,
            'supplier_refund_equivalent_amount' => 653,
        ]), 1, [1, 2]);
        $crossEvent = $events->latestPostedEvent($serviceId, 'refund');
        $crossPayload = json_decode((string) $crossEvent['payload_json'], true, 512, JSON_THROW_ON_ERROR);
        $creditJournal = (int) ($crossEvent['journal_entry_id'] ?? 0);
        $cashJournal = (int) ($crossPayload['supplier_refund_treasury_journal_id'] ?? 0);
        $journalCurrency = static function (PDO $db, int $journalId): string {
            $statement = $db->prepare('SELECT currency FROM journal_entries WHERE id = ?');
            $statement->execute([$journalId]);
            return (string) $statement->fetchColumn();
        };
        $assert($creditJournal > 0 && $cashJournal > 0
            && $journalCurrency($db, $creditJournal) === 'AED'
            && $journalCurrency($db, $cashJournal) === 'PKR'
            && (float) ($crossPayload['cash_refund_credit_consumption_amount'] ?? 0) === 653.0,
            'AED supplier credit falls by AED 653 while PKR 50,242 cash is received');
        $workspace->reverseRefundService([
            'booking_id' => $bookingId, 'service_id' => $serviceId,
            'refund_event_id' => (int) $crossEvent['id'], 'refund_scope' => 'supplier',
            'refund_reverse_reason' => 'Rollback-only cross-currency supplier refund reversal',
        ], 1, [1, 2]);
        $reversal = $events->findPostedEventById((int) $crossEvent['id'], 'refund');
        $assert($reversal === null && $suppliers->availableRefundableCreditForService(
            (int) $service['supplier_id'], $reference, 'AED') >= 653.0,
            'Cross-currency supplier refund reverses both journals and restores AED credit');
    } finally {
        $db->exec('ROLLBACK TO SAVEPOINT supplier_cross_currency_cash_trial');
    }
    $suppliers->createSupplierPayment([
        'supplier_id' => (int) $service['supplier_id'], 'branch_id' => 2,
        'booking_reference' => 'GLOBAL', 'payment_no' => $suppliers->nextSupplierPaymentNumber(),
        'payment_date' => $today, 'currency' => 'PKR', 'payment_scope' => 'global',
        'paid_amount' => 50242, 'payment_method' => 'cash', 'status' => 'paid',
        'actor_user_id' => 1, 'remarks' => 'Rollback-only unrelated supplier balance',
    ]);
    try {
        $workspace->refundService($refundInput, 1, [1, 2]);
        throw new RuntimeException('An AED payment was converted into PKR supplier credit');
    } catch (RuntimeException $error) {
        $assert(str_contains($error->getMessage(), 'not fully backed by released payment credit in PKR'), $error->getMessage());
    }
    $assert((int) $db->query('SELECT COUNT(*) FROM booking_service_events')->fetchColumn() === $before,
        'Unbacked PKR supplier credit wrote an event');
    $existingBooking = (new \App\Services\BookingWorkspaceService($app))->saveBooking([
        'branch_id' => 1, 'booking_status' => 'draft', 'booking_date' => $today,
        'party_label' => 'Lead Traveler / Booking Party',
        'lead_traveler_name' => 'Existing Supplier Payable Regression',
    ], 1, [1, 2])['booking'];
    $existingService = $workspace->saveService([
        'booking_id' => (int) $existingBooking['id'], 'service_type' => 'visa',
        'service_status' => 'Open', 'currency' => 'PKR', 'cost_currency' => 'PKR',
        'supplier_name' => (string) $service['supplier_name_snapshot'],
        'sale_price' => 1000, 'final_sale_price' => 1200,
        'visa_country' => 'UAE', 'visa_type' => 'Visit Visa',
    ], 1, [1, 2])['service'];
    $existingObligation = $suppliers->findObligationByServiceLine(
        (string) $existingBooking['booking_reference'], (string) $existingService['line_reference']);
    $assert((int) $existingObligation['supplier_id'] === (int) $service['supplier_id']
        && (float) $existingObligation['advance_applied_amount'] === 0.0,
        'The existing payable fixture was not open for the same supplier');
    $sourcePaymentId = $suppliers->createSupplierPayment([
        'supplier_id' => (int) $service['supplier_id'], 'branch_id' => 1,
        'booking_reference' => $reference, 'payment_no' => $suppliers->nextSupplierPaymentNumber(),
        'payment_date' => $today, 'currency' => 'PKR', 'payment_scope' => 'booking',
        'paid_amount' => 50242, 'payment_method' => 'cash', 'status' => 'paid',
        'actor_user_id' => 1, 'remarks' => 'Rollback-only released-source fixture',
    ]);
    $cancelEvent = $events->latestPostedEvent($serviceId, 'cancel');
    $events->mergePayload((int) $cancelEvent['id'], ['released_supplier_payment_ids' => [$sourcePaymentId]]);
    $otherBranchPkrCash = $treasury->defaultTreasuryAccountForPayment(1, 'PKR', 'cash');
    $assert(is_array($otherBranchPkrCash), 'Fixture needs a branch 1 PKR cash account');
    foreach ([(int) $cash['id'], (int) $otherBranchPkrCash['id']] as $wrongAccountId) {
        try {
            $workspace->refundService(array_replace($refundInput, [
                'supplier_refund_payment_method' => 'cash',
                'supplier_refund_treasury_account_id' => $wrongAccountId,
            ]), 1, [1, 2]);
            throw new RuntimeException('Wrong-currency or cross-branch supplier cash account was accepted');
        } catch (RuntimeException $error) {
            $assert(str_contains($error->getMessage(), 'must match the actual refund currency and booking branch'),
                $error->getMessage());
        }
    }
    $pkrCashId = $treasury->saveAccount([
        'branch_id' => 2, 'account_type' => 'cash', 'account_name' => 'PKR refund rollback fixture',
        'currency' => 'PKR', 'is_active' => 1, 'is_default' => 0, 'opening_balance' => 0,
        'created_by_user_id' => 1,
    ], [1, 2]);
    $db->exec('SAVEPOINT supplier_source_cash_trial');
    try {
        $workspace->refundService(array_replace($refundInput, [
            'supplier_refund_payment_method' => 'cash',
            'supplier_refund_treasury_account_id' => $pkrCashId,
        ]), 1, [1, 2]);
        $cashEvent = $events->latestPostedEvent($serviceId, 'refund');
        $cashDetail = (new \App\Repositories\BookingServiceRefundDetailRepository($app))
            ->findByServiceEventId((int) $cashEvent['id']);
        $cashPayload = json_decode((string) $cashEvent['payload_json'], true, 512, JSON_THROW_ON_ERROR);
        $creditJournal = $db->query('SELECT currency FROM journal_entries WHERE id = '
            . (int) ($cashEvent['journal_entry_id'] ?? 0))->fetchColumn();
        $cashJournal = $db->query('SELECT currency FROM journal_entries WHERE id = '
            . (int) ($cashPayload['supplier_refund_treasury_journal_id'] ?? 0))->fetchColumn();
        $assert($creditJournal === 'AED' && $cashJournal === 'PKR'
            && ($cashDetail['refund_currency'] ?? '') === 'PKR'
            && (float) ($cashDetail['refund_source_amount'] ?? 0) === 50242.0,
            'Actual PKR supplier cash and AED credit posted in their own currencies');
    } finally {
        $db->exec('ROLLBACK TO SAVEPOINT supplier_source_cash_trial');
    }
    $workspace->refundService(array_replace($refundInput, [
        'supplier_refund_source_amount' => 1,
        'supplier_refund_exchange_rate' => 1,
    ]), 1, [1, 2]);
    $posted = $events->latestPostedEvent($serviceId, 'refund');
    $payload = json_decode((string) $posted['payload_json'], true, 512, JSON_THROW_ON_ERROR);
    $assert((float) $posted['supplier_refund_amount'] === 653.0
        && ($payload['supplier_refund_detail']['refund_currency'] ?? '') === 'PKR'
        && (float) ($payload['supplier_refund_detail']['refund_source_amount'] ?? 0) === 50242.0
        && (float) ($payload['supplier_refund_detail']['refund_booking_equivalent'] ?? 0) === 653.0
        && abs((float) ($payload['supplier_refund_detail']['refund_exchange_rate_to_booking'] ?? 0) - round(50242 / 653, 8)) < 0.00000001
        && ($payload['supplier_refund_payment_method'] ?? '') === 'supplier_credit',
        'Posted supplier credit lost its PKR source or AED equivalent');
    $detail = (new \App\Repositories\BookingServiceRefundDetailRepository($app))->findByServiceEventId((int) $posted['id']);
    $advanceId = (int) ($payload['retained_credit_conversion']['supplier_advance_ids'][0] ?? 0);
    $advance = $suppliers->findAdvanceById($advanceId);
    $originalPayment = $suppliers->findSupplierPaymentById($paymentId);
    $existingObligationAfterRefund = $suppliers->findObligationById((int) $existingObligation['id']);
    $assert(($detail['refund_currency'] ?? '') === 'PKR'
        && (float) ($detail['refund_source_amount'] ?? 0) === 50242.0
        && ($advance['currency'] ?? '') === 'PKR'
        && (int) ($advance['branch_id'] ?? 0) === 1
        && (float) ($advance['deposit_amount'] ?? 0) === 50242.0
        && (int) ($advance['source_supplier_payment_id'] ?? 0) === $sourcePaymentId
        && (float) ($originalPayment['converted_advance_amount'] ?? 0) === 0.0
        && ($payload['retained_credit_conversion']['automatic_applications'] ?? null) === [],
        'Cross-currency credit converted an AED payment or diverged from the PKR refund detail');
    $assert((float) ($advance['available_amount'] ?? 0) === 50242.0
        && (float) ($existingObligationAfterRefund['advance_applied_amount'] ?? -1) === 0.0,
        'Posting supplier refund credit automatically settled an existing booking');
    $futureBooking = (new \App\Services\BookingWorkspaceService($app))->saveBooking([
        'branch_id' => 1, 'booking_status' => 'draft', 'booking_date' => $today,
        'party_label' => 'Lead Traveler / Booking Party',
        'lead_traveler_name' => 'Future Supplier Payable Regression',
    ], 1, [1, 2])['booking'];
    $futureService = $workspace->saveService([
        'booking_id' => (int) $futureBooking['id'], 'service_type' => 'visa',
        'service_status' => 'Open', 'currency' => 'PKR', 'cost_currency' => 'PKR',
        'supplier_name' => (string) $service['supplier_name_snapshot'],
        'sale_price' => 20000, 'final_sale_price' => 25000,
        'visa_country' => 'UAE', 'visa_type' => 'Visit Visa',
    ], 1, [1, 2])['service'];
    $futureObligation = $suppliers->findObligationByServiceLine(
        (string) $futureBooking['booking_reference'], (string) $futureService['line_reference']);
    $assert((float) ($futureObligation['advance_applied_amount'] ?? 0) === 20000.0
        && (float) ($suppliers->findAdvanceById($advanceId)['available_amount'] ?? 0) === 30242.0
        && (float) ($suppliers->findObligationById((int) $existingObligation['id'])['advance_applied_amount'] ?? -1) === 0.0,
        'Future booking did not use the supplier advance pool independently of the old booking');
    $supplierReport = (new \App\Services\SupplierLedgerService($app))->report([
        'currency' => 'PKR', 'supplierId' => (int) $service['supplier_id'],
        'bookingReference' => $reference,
    ], [2]);
    $retainedRows = array_values(array_filter((array) ($supplierReport['rows'] ?? []),
        static fn (array $row): bool => ($row['entry_type'] ?? '') === 'Supplier refund retained as supplier credit'));
    $assert(count($retainedRows) === 1
        && ($retainedRows[0]['currency'] ?? '') === 'PKR'
        && (float) ($retainedRows[0]['raw_debit_amount'] ?? -1) === 0.0
        && (float) ($retainedRows[0]['raw_credit_amount'] ?? -1) === 0.0,
        'Retaining cross-currency supplier credit created another supplier cash movement');
    $assert(array_reduce((array) ($supplierReport['rows'] ?? []),
        static fn (bool $valid, array $row): bool => $valid
            && ($row['booking_reference'] ?? '') !== (string) $futureBooking['booking_reference'], true),
        'The source booking ledger included the later booking advance application');
    echo '[PASS] Expected-currency supplier credit derives AED equivalent server-side and preserves PKR source', PHP_EOL;
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
}
