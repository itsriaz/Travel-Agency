<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

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
$serviceRepository = new \App\Repositories\BookingServiceRepository($app);
$customerRepository = new \App\Repositories\CustomerPaymentRepository($app);
$supplierRepository = new \App\Repositories\SupplierRepository($app);
$exchangeRepository = new \App\Repositories\ExchangeRateRepository($app);

$today = date('Y-m-d');
$actorUserId = 1;
$accessibleBranchIds = [1, 2];
$failures = [];
$check = static function (bool $ok, string $label, mixed $detail = null) use (&$failures): void {
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $label;
    if ($detail !== null) {
        echo ' - ' . json_encode($detail, JSON_UNESCAPED_SLASHES);
    }
    echo PHP_EOL;
    if (! $ok) {
        $failures[] = $label;
    }
};

echo 'Booking exchange-rate edit regression' . PHP_EOL;
echo 'Started: ' . date(DATE_ATOM) . PHP_EOL . PHP_EOL;

$db->beginTransaction();

try {
    $customer = $db->query(
        'SELECT id, full_name, mobile
         FROM travelers
         WHERE branch_id = 1
         ORDER BY id ASC
         LIMIT 1'
    )->fetch(PDO::FETCH_ASSOC) ?: [];
    if ((int) ($customer['id'] ?? 0) <= 0) {
        throw new RuntimeException('A branch-1 customer is required for this regression.');
    }

    $treasuryStatement = $db->prepare(
        'INSERT INTO treasury_accounts (
            branch_id, account_type, account_name, account_code, currency,
            opening_balance, opening_balance_date, is_default, is_active, created_by_user_id
         ) VALUES (
            1, "cash", :account_name, :account_code, "AED",
            0, :opening_date, 0, 1, :user_id
         )'
    );
    $treasuryStatement->execute([
        'account_name' => 'QA Booking FX AED Cash ' . date('His'),
        'account_code' => 'QA-BKFX-' . date('His') . random_int(10, 99),
        'opening_date' => $today,
        'user_id' => $actorUserId,
    ]);
    $treasuryAccountId = (int) $db->lastInsertId();

    $bookingResult = $bookingWorkspace->saveBooking([
        'branch_id' => 1,
        'booking_status' => 'draft',
        'booking_date' => $today,
        'party_label' => 'Lead Traveler / Booking Party',
        'selected_customer_id' => (int) $customer['id'],
        'lead_traveler_name' => (string) ($customer['full_name'] ?? 'Exchange Rate Test'),
        'contact_mobile' => (string) ($customer['mobile'] ?? ''),
        'remarks' => 'Rollback-only booking exchange rate regression',
    ], $actorUserId, $accessibleBranchIds);
    $booking = (array) ($bookingResult['booking'] ?? []);
    $bookingId = (int) ($booking['id'] ?? 0);
    $bookingReference = (string) ($booking['booking_reference'] ?? '');

    $serviceResult = $serviceWorkspace->saveService([
        'booking_id' => $bookingId,
        'service_type' => 'air ticket',
        'service_status' => 'Open',
        'currency' => 'AED',
        'cost_currency' => 'PKR',
        'pricing_exchange_rate' => '0.01250000',
        'pricing_rate_effective_date' => $today,
        'service_charge_currency' => 'AED',
        'service_charge_exchange_rate' => '1.00000000',
        'service_charge_rate_effective_date' => $today,
        'supplier_name' => 'Booking FX Regression Supplier',
        'service_passenger_name' => 'Booking FX Passenger',
        'sale_price' => '1000.00',
        'purchase_cost' => '1000.00',
        'taxes' => '0.00',
        'spyi_amount' => '0.00',
        'aq_yr_pk_amount' => '0.00',
        'yq_amount' => '0.00',
        'oth_amount' => '0.00',
        'vat_input' => '0.00',
        'vat' => '0.00',
        'commission' => '0.00',
        'service_charge' => '20.00',
        'discount_amount' => '0.00',
        'ticket_pnr' => 'BKFX01',
        'ticket_number' => 'BK-FX-001',
        'ticket_airline' => 'TEST AIR',
    ], $actorUserId, $accessibleBranchIds);
    $service = (array) ($serviceResult['service'] ?? []);
    $serviceId = (int) ($service['id'] ?? 0);
    $lineReference = (string) ($service['line_reference'] ?? '');

    $receiptWorkspace->saveSettlementExchangeRate([
        'booking_id' => $bookingId,
        'branch_id' => 1,
        'settlement_rate_from_currency' => 'AED',
        'settlement_rate_to_currency' => 'PKR',
        'settlement_exchange_rate' => '100.00000000',
        'settlement_exchange_rate_effective_date' => $today,
        'synchronize_booking_pricing' => '1',
    ], $actorUserId, $accessibleBranchIds);

    $storedAtOneHundred = $serviceRepository->findServiceById($serviceId);
    $receivableAtOneHundred = $customerRepository->findReceivableByServiceLine($bookingReference, $lineReference);
    $obligationAtOneHundred = $supplierRepository->findObligationByServiceLine($bookingReference, $lineReference);
    $bookingRate = $exchangeRepository->getBookingRate($bookingId, 'PKR', 'AED');

    $check(
        abs((float) ($bookingRate['exchange_rate'] ?? 0) - 0.01) <= 0.00000001,
        'The booking stores one reusable direct/inverse exchange-rate pair',
        $bookingRate
    );
    $check(
        abs((float) ($storedAtOneHundred['pricing_exchange_rate'] ?? 0) - 0.01) <= 0.00000001
        && abs((float) ($storedAtOneHundred['final_sale_price'] ?? 0) - 30.00) <= 0.005,
        'Editing the booking rate recalculates the saved AED invoice from PKR cost plus AED service amount',
        $storedAtOneHundred
    );
    $check(
        abs((float) ($receivableAtOneHundred['due_amount'] ?? 0) - 30.00) <= 0.005
        && ($receivableAtOneHundred['currency'] ?? '') === 'AED'
        && abs((float) ($obligationAtOneHundred['gross_amount'] ?? 0) - 1000.00) <= 0.005
        && ($obligationAtOneHundred['currency'] ?? '') === 'PKR',
        'Receivable and supplier payable remain synchronized in their own currencies',
        ['receivable' => $receivableAtOneHundred, 'obligation' => $obligationAtOneHundred]
    );

    $receiptResult = $receiptWorkspace->saveReceipt([
        'booking_id' => $bookingId,
        'receipt_date' => $today,
        'receipt_currency' => 'AED',
        'received_amount' => '30.00',
        'payment_method' => 'cash',
        'treasury_account_id' => $treasuryAccountId,
        'charges_amount' => '0.00',
        'receipt_status' => 'received',
        'receipt_remarks' => 'Fully paid before correcting the booking exchange rate',
    ], $actorUserId, $accessibleBranchIds);
    $receiptBeforeCorrection = (array) ($receiptResult['receipt'] ?? []);

    $check(
        abs((float) ($receiptBeforeCorrection['received_amount'] ?? 0) - 30.00) <= 0.005
        && abs((float) ($receiptBeforeCorrection['allocated_amount'] ?? 0) - 30.00) <= 0.005
        && abs((float) ($receiptBeforeCorrection['unallocated_amount'] ?? 0)) <= 0.005,
        'The original AED 30 invoice can be fully paid before its exchange rate is corrected',
        $receiptBeforeCorrection
    );

    $receiptWorkspace->saveSettlementExchangeRate([
        'booking_id' => $bookingId,
        'branch_id' => 1,
        'settlement_rate_from_currency' => 'AED',
        'settlement_rate_to_currency' => 'PKR',
        'settlement_exchange_rate' => '80.00000000',
        'settlement_exchange_rate_effective_date' => $today,
        'synchronize_booking_pricing' => '1',
    ], $actorUserId, $accessibleBranchIds);

    $storedAtEighty = $serviceRepository->findServiceById($serviceId);
    $receivableAtEighty = $customerRepository->findReceivableByServiceLine($bookingReference, $lineReference);
    $receiptAfterCorrection = $customerRepository->findReceiptById((int) ($receiptBeforeCorrection['id'] ?? 0));
    $pairCountStatement = $db->prepare(
        'SELECT COUNT(*)
         FROM booking_exchange_rates
         WHERE booking_id = :booking_id'
    );
    $pairCountStatement->execute(['booking_id' => $bookingId]);

    $check(
        (int) $pairCountStatement->fetchColumn() === 1,
        'Correcting the rate updates the booking pair instead of creating duplicates'
    );
    $check(
        abs((float) ($storedAtEighty['pricing_exchange_rate'] ?? 0) - 0.0125) <= 0.00000001
        && abs((float) ($storedAtEighty['final_sale_price'] ?? 0) - 33.00) <= 0.005
        && abs((float) ($receivableAtEighty['due_amount'] ?? 0) - 33.00) <= 0.005,
        'A later correction updates service, invoice, receivable, profit and journals through the audited correction path',
        ['service' => $storedAtEighty, 'receivable' => $receivableAtEighty]
    );
    $check(
        abs((float) ($receivableAtEighty['allocated_amount'] ?? 0) - 30.00) <= 0.005
        && abs((float) ($receivableAtEighty['outstanding_amount'] ?? 0) - 3.00) <= 0.005
        && abs((float) ($receiptAfterCorrection['received_amount'] ?? 0) - 30.00) <= 0.005
        && abs((float) ($receiptAfterCorrection['allocated_amount'] ?? 0) - 30.00) <= 0.005
        && abs((float) ($receiptAfterCorrection['unallocated_amount'] ?? 0)) <= 0.005,
        'Correcting the rate preserves the AED 30 receipt and allocation while exposing only the new AED 3 balance',
        ['receivable' => $receivableAtEighty, 'receipt' => $receiptAfterCorrection]
    );

    $foundation = (new \App\Services\CustomerPaymentFoundationService($app))->buildWorkspacePreview(
        $bookingReference,
        (int) ($booking['lead_traveler_id'] ?? 0) ?: null,
        $accessibleBranchIds,
        [
            'booking_id' => $bookingId,
            'booking_reference' => $bookingReference,
            'booking_date' => (string) ($booking['booking_date'] ?? ''),
            'branch_id' => 1,
        ]
    );
    $loadedRates = (array) ($foundation['dailySettlementRates'] ?? []);
    $check(
        ($loadedRates['AED->PKR']['scope'] ?? '') === 'booking'
        && abs((float) ($loadedRates['AED->PKR']['exchangeRate'] ?? 0) - 80.00) <= 0.00000001
        && ($loadedRates['PKR->AED']['scope'] ?? '') === 'booking'
        && abs((float) ($loadedRates['PKR->AED']['exchangeRate'] ?? 0) - 0.0125) <= 0.00000001,
        'Reloading the booking restores its own rate for pricing and payment reuse',
        [
            'AED->PKR' => $loadedRates['AED->PKR'] ?? null,
            'PKR->AED' => $loadedRates['PKR->AED'] ?? null,
        ]
    );
} catch (Throwable $exception) {
    $failures[] = $exception->getMessage();
    echo '[FAIL] Unexpected exception - ' . $exception->getMessage() . PHP_EOL;
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
}

echo PHP_EOL;
if ($failures !== []) {
    echo 'Booking exchange-rate edit regression failed:' . PHP_EOL;
    foreach ($failures as $failure) {
        echo ' - ' . $failure . PHP_EOL;
    }
    exit(1);
}

echo 'Booking exchange-rate edit regression passed.' . PHP_EOL;
