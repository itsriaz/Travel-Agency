<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';

$app = \App\Core\App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');

$apply = in_array('--apply', $argv ?? [], true);
$marker = 'QA-RIAZ-RELEASE-20260724';
$today = date('Y-m-d');
$branchId = 1;
$actorUserId = 1;
$accessibleBranchIds = [1, 2];

echo 'Riaz persistent release-truth scenario' . PHP_EOL;
echo 'Mode: ' . ($apply ? 'APPLY - RECORDS WILL REMAIN' : 'DRY RUN') . PHP_EOL;
echo 'Marker: ' . $marker . PHP_EOL . PHP_EOL;

$existing = $db->prepare(
    'SELECT booking_reference
     FROM bookings
     WHERE remarks LIKE :marker
     ORDER BY id'
);
$existing->execute(['marker' => '%' . $marker . '%']);
$existingReferences = $existing->fetchAll(PDO::FETCH_COLUMN) ?: [];
if ($existingReferences !== []) {
    echo 'Scenario already exists: ' . implode(', ', array_map('strval', $existingReferences)) . PHP_EOL;
    exit(2);
}

echo 'Planned retained cases:' . PHP_EOL;
echo '  QA1  PKR invoice; agency fee corrected from PKR to AED; full receipt and full supplier payment.' . PHP_EOL;
echo '  QA2  PKR invoice; partial customer payment; supplier unpaid.' . PHP_EOL;
echo '  QA3  PKR supplier cost + USD service fee + AED invoice; customer pays PKR through explicit FX.' . PHP_EOL;
echo '  QA4  AED supplier/invoice + PKR service fee; supplier payment corrected from AED 1,000 to AED 900.' . PHP_EOL;
echo '  QA5  Fully paid PKR ticket; cancellation with cash supplier/customer refund.' . PHP_EOL;
echo '  QA6  Fully paid PKR ticket; cancellation retained as customer credit and supplier-account credit.' . PHP_EOL;
echo '  QA7  Same customer/supplier follow-up; consumes QA6 customer and supplier credits.' . PHP_EOL;
echo '  QA8/QA9  Payment posted to wrong supplier, then moved to the correct supplier without another cash movement.' . PHP_EOL;
echo '  QA10/QA11 Supplier overpayment becomes advance and is consumed by the next payable.' . PHP_EOL;
echo '  QA12 Booking supplier corrected before payment; obligation follows the corrected supplier.' . PHP_EOL;

if (! $apply) {
    echo PHP_EOL . 'Dry run complete. Re-run with --apply to create and retain these records.' . PHP_EOL;
    exit(0);
}

$bookingWorkspace = new \App\Services\BookingWorkspaceService($app);
$travelerWorkspace = new \App\Services\TravelerWorkspaceService($app);
$serviceWorkspace = new \App\Services\ServiceWorkspaceService($app);
$receiptWorkspace = new \App\Services\CustomerReceiptWorkspaceService($app);
$supplierSettlement = new \App\Services\SupplierSettlementWorkspaceService($app);
$treasuryRepository = new \App\Repositories\TreasuryRepository($app);
$exchangeRates = new \App\Repositories\ExchangeRateRepository($app);
$customerPayments = new \App\Repositories\CustomerPaymentRepository($app);
$supplierRepository = new \App\Repositories\SupplierRepository($app);

$scenario = [];
$travelerIds = [];
$treasuryIds = [];

$serviceInput = static function (array $case, int $bookingId, int $serviceId = 0): array {
    return [
        'booking_id' => $bookingId,
        'service_id' => $serviceId,
        'service_type' => 'air ticket',
        'service_status' => 'Open',
        'currency' => $case['invoice_currency'],
        'cost_currency' => $case['cost_currency'],
        'pricing_exchange_rate' => (string) $case['pricing_rate'],
        'pricing_rate_effective_date' => $case['date'],
        'service_charge_currency' => $case['service_currency'],
        'service_charge_exchange_rate' => (string) $case['service_rate'],
        'service_charge_rate_effective_date' => $case['date'],
        'supplier_name' => $case['supplier'],
        'service_passenger_name' => $case['customer'],
        'sale_price' => number_format((float) $case['cost'], 2, '.', ''),
        'purchase_cost' => number_format((float) $case['cost'], 2, '.', ''),
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
        'service_charge' => number_format((float) $case['service_amount'], 2, '.', ''),
        'discount_amount' => '0.00',
        'final_sale_price' => number_format((float) $case['invoice_amount'], 2, '.', ''),
        'due_date' => $case['date'],
        'remarks' => $case['marker'] . ' / ' . $case['key'],
        'ticket_pnr' => 'RZ' . $case['key'] . 'PNR',
        'ticket_number' => 'RZ-' . $case['key'] . '-TKT',
        'ticket_airline' => $case['supplier'],
        'ticket_sector_from' => 'ISB',
        'ticket_sector_to' => 'DXB',
        'ticket_departure_date' => $case['date'],
        'ticket_return_date' => '',
        'ticket_class' => 'Economy',
        'ticket_fare' => number_format((float) $case['cost'], 2, '.', ''),
        'ticket_tax' => '0.00',
        'ticket_vat' => '0.00',
        'ticket_commission' => '0.00',
        'ticket_remarks' => $case['marker'],
    ];
};

$db->beginTransaction();
try {
    $sourceStatement = $db->prepare('SELECT id FROM business_sources WHERE LOWER(name) = "riaz" LIMIT 1');
    $sourceStatement->execute();
    $businessSourceId = (int) ($sourceStatement->fetchColumn() ?: 0);
    if ($businessSourceId <= 0) {
        $insertSource = $db->prepare(
            'INSERT INTO business_sources (code, name, description, is_system, is_active)
             VALUES ("qa_riaz_release", "Riaz", :description, 0, 1)'
        );
        $insertSource->execute(['description' => $marker . ' release verification account holder']);
        $businessSourceId = (int) $db->lastInsertId();
    }

    foreach (['PKR', 'AED', 'USD'] as $currency) {
        $accountName = 'QA Riaz Cash ' . $currency;
        $accountStatement = $db->prepare(
            'SELECT id
             FROM treasury_accounts
             WHERE branch_id = :branch_id
               AND account_name = :account_name
               AND currency = :currency
             LIMIT 1'
        );
        $accountStatement->execute([
            'branch_id' => $branchId,
            'account_name' => $accountName,
            'currency' => $currency,
        ]);
        $accountId = (int) ($accountStatement->fetchColumn() ?: 0);
        if ($accountId <= 0) {
            $accountId = $treasuryRepository->saveAccount([
                'branch_id' => $branchId,
                'account_type' => 'cash',
                'account_name' => $accountName,
                'currency' => $currency,
                'opening_balance' => $currency === 'PKR' ? 2000000.00 : 20000.00,
                'opening_balance_date' => $today,
                'is_active' => 1,
                'notes' => $marker . ' dedicated test account',
                'created_by_user_id' => $actorUserId,
            ], $accessibleBranchIds);
        }
        $treasuryIds[$currency] = $accountId;
    }

    foreach ([
        ['AED', 'PKR', 80.0],
        ['PKR', 'AED', 0.0125],
        ['USD', 'PKR', 320.0],
        ['PKR', 'USD', 0.003125],
        ['USD', 'AED', 4.0],
        ['AED', 'USD', 0.25],
    ] as [$fromCurrency, $toCurrency, $rate]) {
        $exchangeRates->upsertDailyRate($fromCurrency, $toCurrency, $today, $rate, $branchId, $actorUserId);
    }

    $cases = [
        'QA1' => ['customer' => 'QA-RIAZ-ABC1', 'supplier' => 'QA XYZ PKR', 'cost' => 80000, 'cost_currency' => 'PKR', 'invoice_currency' => 'PKR', 'pricing_rate' => 1, 'service_amount' => 5000, 'service_currency' => 'PKR', 'service_rate' => 1, 'invoice_amount' => 85000],
        'QA2' => ['customer' => 'QA-RIAZ-ABC2', 'supplier' => 'QA XYZ PKR', 'cost' => 50000, 'cost_currency' => 'PKR', 'invoice_currency' => 'PKR', 'pricing_rate' => 1, 'service_amount' => 3000, 'service_currency' => 'PKR', 'service_rate' => 1, 'invoice_amount' => 53000],
        'QA3' => ['customer' => 'QA-RIAZ-ABC3', 'supplier' => 'QA XYZ PKR', 'cost' => 80000, 'cost_currency' => 'PKR', 'invoice_currency' => 'AED', 'pricing_rate' => 0.0125, 'service_amount' => 10, 'service_currency' => 'USD', 'service_rate' => 4, 'invoice_amount' => 1040],
        'QA4' => ['customer' => 'QA-RIAZ-ABC4', 'supplier' => 'QA XYZ AED', 'cost' => 1000, 'cost_currency' => 'AED', 'invoice_currency' => 'AED', 'pricing_rate' => 1, 'service_amount' => 8000, 'service_currency' => 'PKR', 'service_rate' => 0.0125, 'invoice_amount' => 1100],
        'QA5' => ['customer' => 'QA-RIAZ-CASH-REFUND', 'supplier' => 'QA REFUND AIR', 'cost' => 50000, 'cost_currency' => 'PKR', 'invoice_currency' => 'PKR', 'pricing_rate' => 1, 'service_amount' => 5000, 'service_currency' => 'PKR', 'service_rate' => 1, 'invoice_amount' => 55000],
        'QA6' => ['customer' => 'QA-RIAZ-CREDIT-CUSTOMER', 'supplier' => 'QA CREDIT AIR', 'cost' => 50000, 'cost_currency' => 'PKR', 'invoice_currency' => 'PKR', 'pricing_rate' => 1, 'service_amount' => 5000, 'service_currency' => 'PKR', 'service_rate' => 1, 'invoice_amount' => 55000],
        'QA7' => ['customer' => 'QA-RIAZ-CREDIT-CUSTOMER', 'supplier' => 'QA CREDIT AIR', 'cost' => 60000, 'cost_currency' => 'PKR', 'invoice_currency' => 'PKR', 'pricing_rate' => 1, 'service_amount' => 5000, 'service_currency' => 'PKR', 'service_rate' => 1, 'invoice_amount' => 65000],
        'QA8' => ['customer' => 'QA-RIAZ-WRONG-PAYMENT', 'supplier' => 'QA WRONG PAYMENT SUPPLIER', 'cost' => 45000, 'cost_currency' => 'PKR', 'invoice_currency' => 'PKR', 'pricing_rate' => 1, 'service_amount' => 5000, 'service_currency' => 'PKR', 'service_rate' => 1, 'invoice_amount' => 50000],
        'QA9' => ['customer' => 'QA-RIAZ-RIGHT-PAYMENT', 'supplier' => 'QA RIGHT PAYMENT SUPPLIER', 'cost' => 45000, 'cost_currency' => 'PKR', 'invoice_currency' => 'PKR', 'pricing_rate' => 1, 'service_amount' => 5000, 'service_currency' => 'PKR', 'service_rate' => 1, 'invoice_amount' => 50000],
        'QA10' => ['customer' => 'QA-RIAZ-OVERPAYMENT', 'supplier' => 'QA ADVANCE AIR', 'cost' => 20000, 'cost_currency' => 'PKR', 'invoice_currency' => 'PKR', 'pricing_rate' => 1, 'service_amount' => 2000, 'service_currency' => 'PKR', 'service_rate' => 1, 'invoice_amount' => 22000],
        'QA11' => ['customer' => 'QA-RIAZ-ADVANCE-NEXT', 'supplier' => 'QA ADVANCE AIR', 'cost' => 8000, 'cost_currency' => 'PKR', 'invoice_currency' => 'PKR', 'pricing_rate' => 1, 'service_amount' => 1000, 'service_currency' => 'PKR', 'service_rate' => 1, 'invoice_amount' => 9000],
        'QA12' => ['customer' => 'QA-RIAZ-BOOKING-SUPPLIER-EDIT', 'supplier' => 'QA WRONG BOOKING SUPPLIER', 'cost' => 30000, 'cost_currency' => 'PKR', 'invoice_currency' => 'PKR', 'pricing_rate' => 1, 'service_amount' => 3000, 'service_currency' => 'PKR', 'service_rate' => 1, 'invoice_amount' => 33000],
    ];

    $createCase = function (string $key, array $definition) use (
        &$scenario,
        &$travelerIds,
        $marker,
        $today,
        $branchId,
        $actorUserId,
        $accessibleBranchIds,
        $businessSourceId,
        $travelerWorkspace,
        $bookingWorkspace,
        $serviceWorkspace,
        $customerPayments,
        $supplierRepository,
        $serviceInput
    ): void {
        $definition += ['key' => $key, 'date' => $today, 'marker' => $marker];
        $customer = (string) $definition['customer'];
        $travelerId = (int) ($travelerIds[$customer] ?? 0);
        if ($travelerId <= 0) {
            $travelerResult = $travelerWorkspace->saveTraveler([
                'booking_id' => 0,
                'traveler_branch_id' => $branchId,
                'traveler_role' => 'lead',
                'first_name' => $customer,
                'last_name' => '',
                'full_name' => $customer,
                'gender' => 'unspecified',
                'mobile' => '',
                'passport_number' => '',
                'notes' => $marker . ' retained customer',
            ], $actorUserId, $accessibleBranchIds);
            $travelerId = (int) (($travelerResult['traveler']['id'] ?? 0));
            if ($travelerId <= 0) {
                throw new RuntimeException($key . ' traveler creation failed.');
            }
            $travelerIds[$customer] = $travelerId;
        }

        $bookingResult = $bookingWorkspace->saveBooking([
            'branch_id' => $branchId,
            'business_source_id' => $businessSourceId,
            'selected_customer_id' => $travelerId,
            'booking_status' => 'draft',
            'booking_date' => $today,
            'due_date' => $today,
            'party_label' => 'Lead Traveler / Booking Party',
            'lead_traveler_name' => $customer,
            'contact_mobile' => '',
            'passport_number' => '',
            'remarks' => $marker . ' / ' . $key,
        ], $actorUserId, $accessibleBranchIds);
        $booking = (array) ($bookingResult['booking'] ?? []);
        $bookingId = (int) ($booking['id'] ?? 0);
        $bookingReference = (string) ($booking['booking_reference'] ?? '');
        if ($bookingId <= 0 || $bookingReference === '') {
            throw new RuntimeException($key . ' booking creation failed.');
        }

        $serviceResult = $serviceWorkspace->saveService(
            $serviceInput($definition, $bookingId),
            $actorUserId,
            $accessibleBranchIds
        );
        $service = (array) ($serviceResult['service'] ?? []);
        $serviceId = (int) ($service['id'] ?? 0);
        $lineReference = (string) ($service['line_reference'] ?? '');
        $receivable = $customerPayments->findReceivableByServiceLine($bookingReference, $lineReference);
        $obligation = $supplierRepository->findObligationByServiceLine($bookingReference, $lineReference);
        if ($serviceId <= 0 || $receivable === null || $obligation === null) {
            throw new RuntimeException($key . ' financial positions were not created.');
        }
        if (abs((float) $receivable['due_amount'] - (float) $definition['invoice_amount']) > 0.005) {
            throw new RuntimeException($key . ' invoice amount does not match the planned value.');
        }
        if (abs((float) $obligation['gross_amount'] - (float) $definition['cost']) > 0.005) {
            throw new RuntimeException($key . ' supplier payable does not match the planned value.');
        }

        $scenario[$key] = [
            'definition' => $definition,
            'traveler_id' => $travelerId,
            'booking_id' => $bookingId,
            'booking_reference' => $bookingReference,
            'service_id' => $serviceId,
            'line_reference' => $lineReference,
            'receivable_id' => (int) $receivable['id'],
            'obligation_id' => (int) $obligation['id'],
            'supplier_id' => (int) ($service['supplier_id'] ?? 0),
        ];
    };

    foreach (['QA1', 'QA2', 'QA3', 'QA4', 'QA5', 'QA6', 'QA7', 'QA8', 'QA9', 'QA10', 'QA12'] as $key) {
        $createCase($key, $cases[$key]);
    }

    // QA1: change the agency fee from PKR 5,000 to AED 100 (= PKR 8,000), so invoice becomes PKR 88,000.
    $qa1Corrected = $cases['QA1'];
    $qa1Corrected['service_amount'] = 100;
    $qa1Corrected['service_currency'] = 'AED';
    $qa1Corrected['service_rate'] = 80;
    $qa1Corrected['invoice_amount'] = 88000;
    $qa1Corrected += ['key' => 'QA1', 'date' => $today, 'marker' => $marker];
    $serviceWorkspace->saveService(
        $serviceInput($qa1Corrected, $scenario['QA1']['booking_id'], $scenario['QA1']['service_id']),
        $actorUserId,
        $accessibleBranchIds
    );
    $scenario['QA1']['definition'] = $qa1Corrected;

    $receive = function (
        string $key,
        string $currency,
        float $amount,
        ?float $targetInvoiceAmount = null,
        ?float $rate = null
    ) use (
        &$scenario,
        $today,
        $marker,
        $actorUserId,
        $accessibleBranchIds,
        $receiptWorkspace,
        $treasuryIds
    ): array {
        $targetCurrency = (string) $scenario[$key]['definition']['invoice_currency'];
        $input = [
            'booking_id' => $scenario[$key]['booking_id'],
            'receipt_date' => $today,
            'receipt_currency' => $currency,
            'received_amount' => number_format($amount, 2, '.', ''),
            'payment_method' => 'cash',
            'treasury_account_id' => $treasuryIds[$currency],
            'receipt_status' => 'received',
            'charges_amount' => '0.00',
            'receipt_remarks' => $marker . ' / ' . $key . ' customer receipt',
        ];
        if ($currency !== $targetCurrency) {
            if ($targetInvoiceAmount === null || $rate === null) {
                throw new RuntimeException($key . ' requires explicit FX settlement values.');
            }
            $input += [
                'settlement_mode' => 'exchange',
                'settlement_target_receivable_id' => $scenario[$key]['receivable_id'],
                'settlement_target_currency' => $targetCurrency,
                'settlement_target_receivable_amount' => number_format($targetInvoiceAmount, 2, '.', ''),
                'settlement_target_payment_amount' => number_format($amount, 2, '.', ''),
                'settlement_rate_from_currency' => $targetCurrency,
                'settlement_rate_to_currency' => $currency,
                'settlement_exchange_rate' => (string) $rate,
                'settlement_exchange_rate_effective_date' => $today,
            ];
        }

        $result = $receiptWorkspace->saveReceipt($input, $actorUserId, $accessibleBranchIds);
        $scenario[$key]['receipt_id'] = (int) ($result['receipt']['id'] ?? 0);
        $scenario[$key]['receipt_no'] = (string) ($result['receipt']['receipt_no'] ?? '');

        return $result;
    };

    $receive('QA1', 'PKR', 88000);
    $receive('QA2', 'PKR', 20000);
    $receive('QA3', 'PKR', 83200, 1040, 80);
    $receive('QA4', 'AED', 500);
    $receive('QA5', 'PKR', 55000);
    $receive('QA6', 'PKR', 55000);

    $paySupplier = function (array $keys, float $amount, string $currency, string $reference) use (
        &$scenario,
        $branchId,
        $today,
        $marker,
        $actorUserId,
        $accessibleBranchIds,
        $supplierSettlement,
        $treasuryIds
    ): array {
        $first = $scenario[$keys[0]];
        $result = $supplierSettlement->recordGlobalPostpaidSupplierPayment([
            'branch_id' => $branchId,
            'supplier_id' => $first['supplier_id'],
            'global_supplier_obligation_id' => array_map(
                static fn (string $key): int => (int) $scenario[$key]['obligation_id'],
                $keys
            ),
            'supplier_payment_currency' => $currency,
            'supplier_paid_amount' => number_format($amount, 2, '.', ''),
            'supplier_payment_date' => $today,
            'supplier_payment_method' => 'cash',
            'supplier_treasury_account_id' => $treasuryIds[$currency],
            'supplier_reference_number' => $reference,
            'supplier_payment_remarks' => $marker . ' / ' . implode('+', $keys),
        ], $actorUserId, $accessibleBranchIds);
        foreach ($keys as $key) {
            $scenario[$key]['supplier_payment_id'] = (int) ($result['payment']['id'] ?? 0);
            $scenario[$key]['supplier_payment_no'] = (string) ($result['payment']['payment_no'] ?? '');
        }

        return $result;
    };

    $paySupplier(['QA1'], 80000, 'PKR', 'QA-RZ-QA1-PAID');
    $qa4Payment = $paySupplier(['QA4'], 1000, 'AED', 'QA-RZ-QA4-AMOUNT-EDIT');
    $qa4PaymentId = (int) ($qa4Payment['payment']['id'] ?? 0);
    $qa4Correction = $supplierSettlement->correctSupplierPayment([
        'supplier_payment_id' => $qa4PaymentId,
        'supplier_id' => $scenario['QA4']['supplier_id'],
        'payment_date' => $today,
        'currency' => 'AED',
        'paid_amount' => '900.00',
        'payment_method' => 'cash',
        'treasury_account_id' => $treasuryIds['AED'],
        'reference_number' => 'QA-RZ-QA4-CORRECTED-900',
        'bank_card_detail' => '',
        'remarks' => $marker . ' / QA4 corrected from AED 1,000 to AED 900',
        'correction_reason' => 'QA release verification amount correction',
    ], $actorUserId, $accessibleBranchIds);
    $scenario['QA4']['corrected_supplier_payment_id'] = (int) ($qa4Correction['supplier_payment_id'] ?? 0);

    $paySupplier(['QA5'], 50000, 'PKR', 'QA-RZ-QA5-CASH-REFUND');
    $paySupplier(['QA6'], 50000, 'PKR', 'QA-RZ-QA6-RETAIN-CREDIT');

    $serviceWorkspace->cancelService([
        'booking_id' => $scenario['QA5']['booking_id'],
        'service_id' => $scenario['QA5']['service_id'],
        'cancel_reason' => $marker . ' QA5 cash-refund cancellation',
        'cancel_event_date' => $today,
        'cancel_notes' => 'Supplier returns PKR 40,000; the original PKR 15,000 sale/refund difference remains retained.',
    ], $actorUserId, $accessibleBranchIds);
    $serviceWorkspace->settleCancellationFinancials([
        'booking_id' => $scenario['QA5']['booking_id'],
        'service_id' => $scenario['QA5']['service_id'],
        'settlement_reason' => $marker . ' QA5 settlement',
        'settlement_event_date' => $today,
        'customer_penalty_amount' => '0.00',
        'expected_supplier_refund_amount' => '40000.00',
        'agency_fee_refund_amount' => '0.00',
        'settlement_notes' => 'PKR 40,000 cash refund due to customer and from supplier.',
    ], $actorUserId, $accessibleBranchIds);
    $serviceWorkspace->refundService([
        'booking_id' => $scenario['QA5']['booking_id'],
        'service_id' => $scenario['QA5']['service_id'],
        'refund_reason' => $marker . ' QA5 cash refund posted',
        'refund_event_date' => $today,
        'refund_payment_method' => 'cash',
        'refund_treasury_account_id' => $treasuryIds['PKR'],
        'supplier_refund_payment_method' => 'cash',
        'supplier_refund_treasury_account_id' => $treasuryIds['PKR'],
        'customer_refund_amount' => '40000.00',
        'customer_refund_treatment' => 'pay_now',
        'customer_refund_payment_confirmed' => '1',
        'supplier_refund_amount' => '40000.00',
        'refund_notes' => 'Supplier cash received and customer cash paid.',
    ], $actorUserId, $accessibleBranchIds);

    $serviceWorkspace->cancelService([
        'booking_id' => $scenario['QA6']['booking_id'],
        'service_id' => $scenario['QA6']['service_id'],
        'cancel_reason' => $marker . ' QA6 retained-credit cancellation',
        'cancel_event_date' => $today,
        'cancel_notes' => 'Both customer and supplier refunds remain as reusable credit.',
    ], $actorUserId, $accessibleBranchIds);
    $serviceWorkspace->settleCancellationFinancials([
        'booking_id' => $scenario['QA6']['booking_id'],
        'service_id' => $scenario['QA6']['service_id'],
        'settlement_reason' => $marker . ' QA6 settlement',
        'settlement_event_date' => $today,
        'customer_penalty_amount' => '0.00',
        'expected_supplier_refund_amount' => '40000.00',
        'agency_fee_refund_amount' => '0.00',
        'settlement_notes' => 'PKR 40,000 retained for the next booking.',
    ], $actorUserId, $accessibleBranchIds);
    $serviceWorkspace->refundService([
        'booking_id' => $scenario['QA6']['booking_id'],
        'service_id' => $scenario['QA6']['service_id'],
        'refund_reason' => $marker . ' QA6 keep both credits',
        'refund_event_date' => $today,
        'refund_payment_method' => 'cash',
        'supplier_refund_payment_method' => 'supplier_credit',
        'customer_refund_amount' => '0.00',
        'customer_refund_treatment' => 'keep_credit',
        'supplier_refund_amount' => '40000.00',
        'refund_notes' => 'No cash moved; customer and supplier credits retained.',
    ], $actorUserId, $accessibleBranchIds);

    $sourceCreditStatement = $db->prepare(
        'SELECT id, unallocated_amount
         FROM customer_receipts
         WHERE booking_reference = :booking_reference
           AND status <> "void"
           AND unallocated_amount > 0
         ORDER BY id DESC
         LIMIT 1'
    );
    $sourceCreditStatement->execute(['booking_reference' => $scenario['QA6']['booking_reference']]);
    $sourceCredit = $sourceCreditStatement->fetch(PDO::FETCH_ASSOC) ?: [];
    if ((int) ($sourceCredit['id'] ?? 0) <= 0 || (float) ($sourceCredit['unallocated_amount'] ?? 0) < 40000) {
        throw new RuntimeException('QA6 customer credit was not retained correctly.');
    }
    $creditApplyResult = $receiptWorkspace->saveReceipt([
        'booking_id' => $scenario['QA7']['booking_id'],
        'receipt_action' => 'no_receipt',
        'receipt_scope' => 'whole_invoice',
        'receipt_currency' => 'PKR',
        'received_amount' => '0',
        'receipt_date' => $today,
        'payment_method' => 'cash',
        'charges_amount' => '0',
        'receipt_status' => 'received',
        'customer_credit_receipt_id' => (int) $sourceCredit['id'],
        'customer_credit_apply_amount' => '30000.00',
        'advance_receipt_id' => '',
        'advance_apply_amount' => '0',
    ], $actorUserId, $accessibleBranchIds);
    $scenario['QA7']['customer_credit_applied'] = (float) ($creditApplyResult['customer_credit_applied']['allocated_amount'] ?? 0);

    $wrongPayment = $paySupplier(['QA8'], 45000, 'PKR', 'QA-RZ-WRONG-SUPPLIER');
    $wrongPaymentId = (int) ($wrongPayment['payment']['id'] ?? 0);
    $paymentSupplierCorrection = $supplierSettlement->correctSupplierPayment([
        'supplier_payment_id' => $wrongPaymentId,
        'supplier_id' => $scenario['QA9']['supplier_id'],
        'payment_date' => $today,
        'currency' => 'PKR',
        'paid_amount' => '45000.00',
        'payment_method' => 'cash',
        'treasury_account_id' => $treasuryIds['PKR'],
        'reference_number' => 'QA-RZ-PAYMENT-SUPPLIER-CORRECTED',
        'bank_card_detail' => '',
        'remarks' => $marker . ' / payment moved to correct supplier',
        'correction_reason' => 'QA release verification wrong supplier correction',
    ], $actorUserId, $accessibleBranchIds);
    $scenario['QA9']['corrected_supplier_payment_id'] = (int) ($paymentSupplierCorrection['supplier_payment_id'] ?? 0);

    $paySupplier(['QA10'], 25000, 'PKR', 'QA-RZ-OVERPAY-25000');
    $createCase('QA11', $cases['QA11']);

    $qa12Corrected = $cases['QA12'];
    $qa12Corrected['supplier'] = 'QA CORRECT BOOKING SUPPLIER';
    $qa12Corrected += ['key' => 'QA12', 'date' => $today, 'marker' => $marker];
    $qa12Correction = $serviceWorkspace->saveService(
        $serviceInput($qa12Corrected, $scenario['QA12']['booking_id'], $scenario['QA12']['service_id']),
        $actorUserId,
        $accessibleBranchIds
    );
    $scenario['QA12']['definition'] = $qa12Corrected;
    $scenario['QA12']['supplier_id'] = (int) ($qa12Correction['service']['supplier_id'] ?? 0);

    $db->commit();
} catch (Throwable $exception) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, '[FAIL] Entire scenario rolled back: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}

$referenceFile = BASE_PATH . '/storage/qa_riaz_release_truth_20260724.json';
$directory = dirname($referenceFile);
if (! is_dir($directory)) {
    mkdir($directory, 0775, true);
}
file_put_contents($referenceFile, json_encode([
    'marker' => $marker,
    'created_at' => date(DATE_ATOM),
    'branch_id' => $branchId,
    'business_source' => 'Riaz',
    'treasury_accounts' => $treasuryIds,
    'bookings' => array_map(static fn (array $row): array => [
        'booking_reference' => $row['booking_reference'],
        'customer' => $row['definition']['customer'],
        'supplier' => $row['definition']['supplier'],
        'cost_currency' => $row['definition']['cost_currency'],
        'invoice_currency' => $row['definition']['invoice_currency'],
        'service_currency' => $row['definition']['service_currency'],
        'planned_cost' => $row['definition']['cost'],
        'planned_invoice' => $row['definition']['invoice_amount'],
    ], $scenario),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

echo PHP_EOL . 'Persistent scenario committed successfully.' . PHP_EOL;
foreach ($scenario as $key => $row) {
    echo str_pad($key, 5)
        . ' ' . $row['booking_reference']
        . ' | ' . $row['definition']['customer']
        . ' | ' . $row['definition']['supplier']
        . ' | cost ' . $row['definition']['cost_currency'] . ' ' . number_format((float) $row['definition']['cost'], 2)
        . ' | invoice ' . $row['definition']['invoice_currency'] . ' ' . number_format((float) $row['definition']['invoice_amount'], 2)
        . PHP_EOL;
}
echo 'Reference map: ' . $referenceFile . PHP_EOL;
