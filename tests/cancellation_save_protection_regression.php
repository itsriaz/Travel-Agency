<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/Helpers/functions.php';
require BASE_PATH . '/app/Core/bootstrap.php';

$app = \App\Core\App::bootstrap(BASE_PATH);
$db = $app->get('db');
$workspace = new \App\Services\ServiceWorkspaceService($app);
$services = new \App\Repositories\BookingServiceRepository($app);
$events = new \App\Repositories\BookingServiceEventRepository($app);
$customers = new \App\Repositories\CustomerPaymentRepository($app);
$suppliers = new \App\Repositories\SupplierRepository($app);
$sync = new \App\Services\CommercialObligationSyncService($app);
$today = date('Y-m-d');
$failures = [];
$check = static function (string $name, bool $passed) use (&$failures): void {
    echo ($passed ? '[PASS] ' : '[FAIL] ') . $name . PHP_EOL;
    if (! $passed) {
        $failures[] = $name;
    }
};
$expectBlocked = static function (string $name, callable $action, string $expectedMessage = 'cancellation') use ($check): void {
    try {
        $action();
        $check($name, false);
    } catch (RuntimeException $exception) {
        $check($name, str_contains(strtolower($exception->getMessage()), strtolower($expectedMessage)));
    }
};

$db->beginTransaction();
try {
    $result = (new \App\Services\BookingWorkspaceService($app))->saveBooking([
        'branch_id' => 2, 'booking_status' => 'draft', 'booking_date' => $today,
        'party_label' => 'Lead Traveler / Booking Party',
        'lead_traveler_name' => 'Cancellation Save Guard Regression',
        'contact_mobile' => '03001234567', 'remarks' => 'Rollback-only regression',
    ], 1, [1, 2]);
    $booking = $result['booking'];
    $bookingId = (int) $booking['id'];
    $reference = (string) $booking['booking_reference'];
    $input = [
        'booking_id' => $bookingId, 'service_type' => 'visa', 'service_status' => 'Open',
        'currency' => 'AED', 'cost_currency' => 'AED',
        'supplier_name' => 'Cancellation Guard Supplier ' . random_int(100000, 999999),
        'service_passenger_name' => 'Cancellation Guard Passenger',
        'sale_price' => '1000', 'service_charge' => '100', 'vat' => '0',
        'discount_amount' => '0', 'final_sale_price' => '1100',
        'visa_country' => 'UAE', 'visa_type' => 'Visit Visa', 'remarks' => 'Original notes',
    ];
    $result = $workspace->saveService($input, 1, [1, 2]);
    $service = $result['service'];
    $id = (int) $service['id'];
    $line = (string) $service['line_reference'];
    $check('Ordinary service remains eligible for commercial sync', ! $events->hasPostedCancellationSettlement($id));
    $sync->syncForServiceId($id, 1);
    $check('Ordinary invoice amount remains AED 1100', (float) $customers->findReceivableByServiceLine($reference, $line)['due_amount'] === 1100.0);

    (new \App\Services\CustomerReceiptWorkspaceService($app))->saveReceipt([
        'booking_id' => $bookingId, 'receipt_date' => $today, 'receipt_currency' => 'AED',
        'received_amount' => '1100', 'payment_method' => 'cash', 'charges_amount' => '0',
        'receipt_status' => 'received', 'receipt_remarks' => 'Regression full payment',
    ], 1, [1, 2]);
    $workspace->cancelService([
        'booking_id' => $bookingId, 'service_id' => $id,
        'cancel_reason' => 'Regression cancellation', 'cancel_event_date' => $today,
    ], 1, [1, 2]);
    $check('Operational cancellation alone is not a financial settlement', ! $events->hasPostedCancellationSettlement($id));
    $workspace->settleCancellationFinancials([
        'booking_id' => $bookingId, 'service_id' => $id,
        'settlement_reason' => 'Regression cancellation settlement', 'settlement_event_date' => $today,
        'customer_penalty_amount' => '50', 'expected_supplier_refund_amount' => '900',
    ], 1, [1, 2]);
    $check('Financial cancellation settlement is protected', $events->hasPostedCancellationSettlement($id));
    $cash = (new \App\Repositories\TreasuryRepository($app))->defaultTreasuryAccountForPayment(2, 'AED', 'cash');
    $workspace->refundService([
        'booking_id' => $bookingId, 'service_id' => $id,
        'refund_reason' => 'Regression customer refund', 'refund_event_date' => $today,
        'customer_refund_amount' => '100', 'customer_refund_treatment' => 'pay_now',
        'supplier_refund_amount' => '0', 'refund_payment_method' => 'cash',
        'refund_treasury_account_id' => (int) $cash['id'],
    ], 1, [1, 2]);

    $snapshot = static function () use ($db, $reference, $line, $id, $customers, $suppliers, $events): array {
        $query = $db->prepare('SELECT * FROM journal_entries WHERE booking_reference = :reference ORDER BY id');
        $query->execute(['reference' => $reference]);
        $treasury = $db->query('SELECT * FROM treasury_accounts ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        return [
            $customers->findReceivableByServiceLine($reference, $line),
            $suppliers->findObligationByServiceLine($reference, $line),
            $events->postedEventsForService($id), $query->fetchAll(PDO::FETCH_ASSOC), $treasury,
        ];
    };
    $before = $snapshot();
    $check('Settled invoice and supplier cost are AED 250 and AED 100',
        (float) $before[0]['due_amount'] === 250.0 && (float) $before[1]['gross_amount'] === 100.0);
    $sync->syncForServiceId($id, 1);
    $check('Direct commercial sync preserves settlement, refunds, journals and cash', $before === $snapshot());

    $saved = $services->findServiceById($id);
    $edit = array_merge($input, [
        'service_id' => $id, 'service_status' => 'Cancelled',
        'service_updated_at' => $saved['updated_at'], 'remarks' => 'Updated operational notes',
    ]);
    $workspace->saveService($edit, 1, [1, 2]);
    $check('Operational notes save without rebuilding settled financial positions',
        $before === $snapshot() && $services->findServiceById($id)['remarks'] === 'Updated operational notes');
    $edit['service_updated_at'] = $services->findServiceById($id)['updated_at'];
    $supplierCount = (int) $db->query('SELECT COUNT(*) FROM suppliers')->fetchColumn();
    $expectBlocked('Ordinary save cannot change supplier after settlement',
        static fn () => $workspace->saveService(array_merge($edit, ['supplier_name' => 'Replacement Supplier']), 1, [1, 2]));
    $check('Rejected supplier edit does not create a replacement supplier',
        $supplierCount === (int) $db->query('SELECT COUNT(*) FROM suppliers')->fetchColumn());
    $expectBlocked('Ordinary save cannot change cost currency after settlement',
        static fn () => $workspace->saveService(array_merge($edit, ['cost_currency' => 'PKR']), 1, [1, 2]),
        'Saved service financial values must be changed through Quick Financial Edit');
    $expectBlocked('Ordinary save cannot reopen a settled cancellation',
        static fn () => $workspace->saveService(array_merge($edit, ['service_status' => 'Open']), 1, [1, 2]));
    $expectBlocked('Quick Financial Edit cannot relabel a settled cancellation',
        static fn () => $workspace->correctFinancials([
            'booking_id' => $bookingId, 'service_id' => $id, 'corrected_cost_currency' => 'PKR',
            'financial_correction_reason' => 'Regression forbidden currency correction',
        ], 1, [1, 2]));
    $expectBlocked('Deactivation cannot erase settled cancellation positions',
        static fn () => $workspace->deactivateService(['booking_id' => $bookingId, 'service_id' => $id], 1, [1, 2]));
    $check('Rejected changes preserve financial records and posted refund', $before === $snapshot());

    $expectationInput = [
        'booking_id' => $bookingId, 'service_id' => $id,
        'correction_reason' => 'Correct expectation source without changing customer cash', 'correction_event_date' => $today,
        'customer_penalty_amount' => '50', 'customer_penalty_currency' => 'AED', 'customer_penalty_exchange_rate' => '1',
        'agency_fee_refund_amount' => '0', 'settlement_currency' => 'AED', 'settlement_exchange_rate' => '1',
        'expected_supplier_refund_amount' => '69300', 'expected_supplier_refund_currency' => 'PKR',
        'expected_supplier_refund_exchange_rate' => 1 / 77,
        'expected_supplier_refund_rate_date' => '2026-09-25',
        'supplier_refund_conversion_mode' => 'manual_equivalent',
        'expected_supplier_refund_equivalent_amount' => '900',
        'customer_refund_amount' => '100', 'customer_refund_source_amount' => '100',
        'customer_refund_currency' => 'AED', 'customer_refund_exchange_rate' => '1',
        'refund_payment_method' => 'cash', 'refund_treasury_account_id' => (int) $cash['id'],
        'supplier_refund_amount' => '0',
    ];
    $workspace->correctPenaltyRefundWorkflow($expectationInput, 1, [1, 2]);
    $expectationAfter = $snapshot();
    $updatedPayload = json_decode($events->latestPostedEvent($id, 'cancel')['payload_json'], true);
    $refundEventsOnly = static fn (array $rows): array => array_values(array_filter($rows, static fn (array $event): bool => $event['event_type'] === 'refund'));
    $check('Expectation-only correction preserves receivables, payables, posted refund, journals and treasury',
        $before[0] === $expectationAfter[0] && $before[1] === $expectationAfter[1]
        && $refundEventsOnly($before[2]) === $refundEventsOnly($expectationAfter[2])
        && $before[3] === $expectationAfter[3] && $before[4] === $expectationAfter[4]);
    $check('Expectation-only correction saves PKR source and unchanged AED equivalent',
        $updatedPayload['expected_supplier_refund_currency'] === 'PKR'
        && (float) $updatedPayload['expected_supplier_refund_source_amount'] === 69300.0
        && (float) $updatedPayload['expected_supplier_refund_booking_amount'] === 900.0
        && $updatedPayload['expected_supplier_refund_rate_date'] === '2026-09-25');
    $check('Manual equivalent and its penalty currency are saved for audit',
        (float) $updatedPayload['expected_supplier_refund_equivalent_amount'] === 900.0
        && $updatedPayload['expected_supplier_refund_equivalent_currency'] === 'AED'
        && $updatedPayload['expected_supplier_refund_conversion_basis'] === 'manual_equivalent');
    $savedSummary = $workspace->serviceState($bookingId, [1, 2])['services'][0]['customer_refund_summary'];
    $check('Supplier denomination does not change AED customer entitlement or cumulative refund paid',
        $savedSummary['currency'] === 'AED' && (float) $savedSummary['entitlement'] === 850.0
        && (float) $savedSummary['paid'] === 100.0 && (float) $savedSummary['remaining'] === 750.0);
    $auditCount = (int) $db->query("SELECT COUNT(*) FROM audit_logs WHERE event_name = 'service.cancellation_supplier_expectation.corrected'")->fetchColumn();
    $workspace->correctPenaltyRefundWorkflow($expectationInput, 1, [1, 2]);
    $check('Repeated expectation correction does not post another reversal or duplicate correction audit',
        $expectationAfter === $snapshot()
        && $auditCount === (int) $db->query("SELECT COUNT(*) FROM audit_logs WHERE event_name = 'service.cancellation_supplier_expectation.corrected'")->fetchColumn());

    $protectedEvent = $events->latestPostedEvent($id, 'cancel');
    $protectedExpectation = array_intersect_key($updatedPayload, array_flip([
        'expected_supplier_refund_amount', 'expected_supplier_refund_booking_amount',
        'expected_supplier_refund_source_amount', 'expected_supplier_refund_currency',
        'expected_supplier_refund_exchange_rate',
    ]));
    foreach ([
        'Stale expectation payload' => ['payload' => '{}', 'values' => $protectedExpectation],
        'Inconsistent converted expectation totals' => ['payload' => $protectedEvent['payload_json'],
            'values' => array_merge($protectedExpectation, ['expected_supplier_refund_amount' => 901])],
        'Changed economic settlement total' => ['payload' => $protectedEvent['payload_json'],
            'values' => array_merge($protectedExpectation, ['expected_supplier_refund_amount' => 901,
                'expected_supplier_refund_booking_amount' => 901, 'expected_supplier_refund_source_amount' => 69377])],
    ] as $name => $invalidExpectation) {
        try {
            $events->updateSupplierRefundExpectation((int) $protectedEvent['id'], $invalidExpectation['payload'], $invalidExpectation['values']);
            $check($name . ' is rejected at repository boundary', false);
        } catch (RuntimeException $exception) {
            $check($name . ' is rejected at repository boundary', true);
        }
        $check($name . ' leaves financial records unchanged', $expectationAfter === $snapshot());
    }

    $thirdCurrencyService = $workspace->saveService($input, 1, [1, 2])['service'];
    $thirdCurrencyId = (int) $thirdCurrencyService['id'];
    $workspace->cancelService([
        'booking_id' => $bookingId, 'service_id' => $thirdCurrencyId,
        'cancel_reason' => 'Third-currency expectation regression', 'cancel_event_date' => $today,
    ], 1, [1, 2]);
    $workspace->settleCancellationFinancials([
        'booking_id' => $bookingId, 'service_id' => $thirdCurrencyId,
        'settlement_reason' => 'Preserve user-entered expectation currency', 'settlement_event_date' => $today,
        'customer_penalty_amount' => 50, 'customer_penalty_currency' => 'AED',
        'expected_supplier_refund_amount' => 200, 'expected_supplier_refund_currency' => 'USD',
        'supplier_refund_conversion_mode' => 'manual_equivalent',
        'expected_supplier_refund_equivalent_amount' => 700,
        'expected_supplier_refund_exchange_rate' => 3.5, 'expected_supplier_refund_rate_date' => '2026-09-25',
    ], 1, [1, 2]);
    $thirdPayload = json_decode($events->latestPostedEvent($thirdCurrencyId, 'cancel')['payload_json'], true);
    $check('New expectation preserves entered USD currency, amount, FX date and AED equivalent independently of supplier AED',
        $thirdPayload['expected_supplier_refund_currency'] === 'USD'
        && (float) $thirdPayload['expected_supplier_refund_source_amount'] === 200.0
        && (float) $thirdPayload['expected_supplier_refund_exchange_rate'] === 3.5
        && (float) $thirdPayload['expected_supplier_refund_booking_amount'] === 700.0
        && $thirdPayload['expected_supplier_refund_rate_date'] === '2026-09-25');
    $thirdLine = (string) $thirdCurrencyService['line_reference'];
    $unpaidPositions = static fn (): array => [$snapshot(),
        $customers->findReceivableByServiceLine($reference, $thirdLine),
        $suppliers->findObligationByServiceLine($reference, $thirdLine)];
    $unpaidCancelledSnapshot = $unpaidPositions();
    $workspace->saveService(array_merge($input, ['service_id' => $thirdCurrencyId,
        'service_updated_at' => $services->findServiceById($thirdCurrencyId)['updated_at'],
        'service_status' => 'Cancelled', 'remarks' => 'Unpaid cancellation must not rebuild original invoice']), 1, [1, 2]);
    $sync->syncForServiceId($thirdCurrencyId, 1);
    $check('Unpaid settled cancellation note saves and direct sync cannot recreate original customer/supplier balances (BK897 resync regression)',
        $unpaidCancelledSnapshot === $unpaidPositions());

    $rates = new \App\Repositories\ExchangeRateRepository($app);
    $rates->upsertBookingRate($bookingId, 2, 'AED', 'PKR', $today, 77.0, 1);
    $mixedInput = array_merge($input, [
        'cost_currency' => 'PKR', 'sale_price' => '77000',
        'pricing_exchange_rate' => 1 / 77, 'pricing_rate_effective_date' => $today,
        'service_charge_currency' => 'AED', 'service_charge_exchange_rate' => '1',
    ]);
    $mixed = $workspace->saveService($mixedInput, 1, [1, 2])['service'];
    $mixedId = (int) $mixed['id'];
    $workspace->cancelService([
        'booking_id' => $bookingId, 'service_id' => $mixedId,
        'cancel_reason' => 'Mixed currency cancellation regression', 'cancel_event_date' => $today,
    ], 1, [1, 2]);
    $mixedSettlement = [
        'booking_id' => $bookingId, 'service_id' => $mixedId,
        'settlement_reason' => 'Mixed currency settlement regression', 'settlement_event_date' => $today,
        'customer_penalty_amount' => '3850', 'customer_penalty_currency' => 'PKR',
        'customer_penalty_exchange_rate' => 1 / 77, 'settlement_currency' => 'PKR', 'settlement_exchange_rate' => 1 / 77,
        'expected_supplier_refund_amount' => '69300',
        'expected_supplier_refund_currency' => 'PKR', 'expected_supplier_refund_exchange_rate' => 1 / 77,
    ];
    $beforeInvalidSettlement = $snapshot();
    foreach ([
        'Missing mixed-currency FX' => ['expected_supplier_refund_exchange_rate' => '', 'settlement_exchange_rate' => ''],
        'Refund greater than original supplier cost' => ['expected_supplier_refund_amount' => '80000'],
    ] as $name => $invalidFields) {
        try {
            $workspace->settleCancellationFinancials(array_merge($mixedSettlement, $invalidFields), 1, [1, 2]);
            $check($name . ' is rejected', false);
        } catch (RuntimeException $exception) {
            $check($name . ' is rejected', str_contains($exception->getMessage(), 'supplier refund'));
        }
        $check($name . ' leaves financial records unchanged', $beforeInvalidSettlement === $snapshot());
    }
    $workspace->settleCancellationFinancials($mixedSettlement, 1, [1, 2]);
    $mixedPayload = json_decode($events->latestPostedEvent($mixedId, 'cancel')['payload_json'], true);
    $check('New mixed-currency settlement keeps converted customer entitlement in AED, not supplier PKR',
        $mixedPayload['available_customer_refund_credit_currency'] === 'AED'
        && $mixedPayload['target_customer_refund_credit_currency'] === 'AED'
        && (int) $mixedPayload['calculation_version'] === 2);
    $check('Supplier penalty persists in original PKR supplier currency independently of AED customer accounting',
        $mixedPayload['supplier_penalty_currency'] === 'PKR'
        && (float) $mixedPayload['supplier_penalty_source_amount'] === 7700.0
        && (float) $mixedPayload['supplier_penalty_booking_amount'] === 100.0);
    $mixedBefore = $services->findServiceById($mixedId);
    $positionsBeforeRateEdit = $snapshot();
    $rates->upsertBookingRate($bookingId, 2, 'AED', 'PKR', $today, 80.0, 1);
    $rateSync = $workspace->synchronizeBookingExchangeRate($bookingId, 'AED', 'PKR', 1, [1, 2]);
    $check('Booking rate edits skip mixed-currency settled cancellations without changing their positions',
        $rateSync['updated_services'] === 0
        && $mixedBefore === $services->findServiceById($mixedId)
        && $positionsBeforeRateEdit === $snapshot());

    $legacyEvent = $events->latestPostedEvent($id, 'cancel');
    $update = $db->prepare('UPDATE booking_service_events SET payload_json = :payload WHERE id = :id');
    $update->execute(['payload' => json_encode(['supplier_penalty_amount' => 100]), 'id' => $legacyEvent['id']]);
    $check('Legacy cancellation payload is also protected', $events->hasPostedCancellationSettlement($id));
    $update->execute(['payload' => json_encode('invalid settlement payload'), 'id' => $legacyEvent['id']]);
    $expectBlocked('Unreadable cancellation history fails closed', static fn () => $sync->syncForServiceId($id, 1));
} catch (Throwable $exception) {
    $failures[] = $exception->getMessage();
    echo '[FAIL] ' . $exception->getMessage() . PHP_EOL;
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
}
echo 'All fixture data rolled back.' . PHP_EOL;
exit($failures === [] ? 0 : 1);
