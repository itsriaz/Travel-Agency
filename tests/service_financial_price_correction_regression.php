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
$serviceRepository = new \App\Repositories\BookingServiceRepository($app);
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

$moneyPosition = static function (string $bookingReference, string $lineReference) use ($db): array {
    $statement = $db->prepare(
        'SELECT coa.code, ROUND(SUM(jel.debit_amount - jel.credit_amount), 2) AS net_balance
         FROM journal_entries je
         INNER JOIN journal_entry_lines jel ON jel.journal_entry_id = je.id
         INNER JOIN chart_of_accounts coa ON coa.id = jel.account_id
         WHERE je.booking_reference = :booking_reference
           AND je.currency = "AED"
           AND jel.service_line_reference = :service_line_reference
           AND coa.code IN ("AR_CONTROL", "SERVICE_REVENUE", "SERVICE_COST", "AP_CONTROL")
         GROUP BY coa.code'
    );
    $statement->execute([
        'booking_reference' => $bookingReference,
        'service_line_reference' => $lineReference,
    ]);

    $position = [];
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $position[(string) $row['code']] = round((float) $row['net_balance'], 2);
    }

    return $position;
};

$supplierPaymentAllocation = static function (int $paymentId, int $obligationId) use ($db): float {
    $statement = $db->prepare(
        'SELECT COALESCE(SUM(allocated_amount), 0)
         FROM supplier_payment_allocations
         WHERE supplier_payment_id = :payment_id
           AND supplier_obligation_id = :obligation_id'
    );
    $statement->execute([
        'payment_id' => $paymentId,
        'obligation_id' => $obligationId,
    ]);

    return round((float) $statement->fetchColumn(), 2);
};

echo 'Service financial price correction regression' . PHP_EOL;
echo 'Started: ' . date(DATE_ATOM) . PHP_EOL . PHP_EOL;

$db->beginTransaction();

try {
    $bookingResult = $bookingWorkspace->saveBooking([
        'branch_id' => 2,
        'booking_status' => 'draft',
        'booking_date' => $today,
        'party_label' => 'Lead Traveler / Booking Party',
        'lead_traveler_name' => 'Price Correction Regression Customer',
        'contact_mobile' => '03001234567',
        'remarks' => 'Temporary price correction regression',
    ], $actorUserId, $accessibleBranchIds);

    $booking = $bookingResult['booking'] ?? [];
    $bookingId = (int) ($booking['id'] ?? 0);
    $bookingReference = (string) ($booking['booking_reference'] ?? '');

    $serviceResult = $serviceWorkspace->saveService([
        'booking_id' => $bookingId,
        'service_type' => 'visa',
        'service_status' => 'Open',
        'currency' => 'AED',
        'cost_currency' => 'AED',
        'supplier_name' => 'Price Correction Regression Supplier ' . random_int(1000, 9999),
        'service_passenger_name' => 'Price Regression Passenger',
        'sale_price' => '490.00',
        'vat' => '0.00',
        'service_charge' => '260.00',
        'discount_amount' => '0.00',
        'final_sale_price' => '750.00',
        'remarks' => 'BK-000420 pricing-pattern regression service',
        'visa_country' => 'UAE',
        'visa_type' => 'Visit Visa',
        'visa_remarks' => 'Temporary regression data',
    ], $actorUserId, $accessibleBranchIds);

    $service = $serviceResult['service'] ?? [];
    $serviceId = (int) ($service['id'] ?? 0);
    $lineReference = (string) ($service['line_reference'] ?? '');
    $receivable = $customerRepository->findReceivableByServiceLine($bookingReference, $lineReference);
    $obligation = $supplierRepository->findObligationByServiceLine($bookingReference, $lineReference);

    $check(
        'Non-air service starts with synchronized legacy cost mirror, receivable, and payable',
        $serviceId > 0
        && abs((float) ($service['sale_price'] ?? 0) - 490.00) <= 0.005
        && abs((float) ($service['purchase_cost'] ?? 0) - 490.00) <= 0.005
        && abs((float) ($service['final_sale_price'] ?? 0) - 750.00) <= 0.005
        && abs((float) ($receivable['due_amount'] ?? 0) - 750.00) <= 0.005
        && abs((float) ($obligation['gross_amount'] ?? 0) - 490.00) <= 0.005
    );

    $receiptResult = $receiptWorkspace->saveReceipt([
        'booking_id' => $bookingId,
        'receipt_date' => $today,
        'receipt_currency' => 'AED',
        'received_amount' => '750.00',
        'payment_method' => 'cash',
        'charges_amount' => '0.00',
        'receipt_status' => 'received',
        'receipt_remarks' => 'Full customer receipt before price corrections',
    ], $actorUserId, $accessibleBranchIds);
    $receiptId = (int) ($receiptResult['receipt']['id'] ?? 0);

    // Split the same receipt/receivable settlement across two rows. This models
    // repeated allocations and ensures a later release updates the receipt header
    // cumulatively instead of reusing a stale header snapshot for each row.
    $allocationLookup = $db->prepare(
        'SELECT id
         FROM customer_receipt_allocations
         WHERE customer_receipt_id = :receipt_id
           AND customer_receivable_item_id = :receivable_id
         ORDER BY id ASC
         LIMIT 1'
    );
    $allocationLookup->execute([
        'receipt_id' => $receiptId,
        'receivable_id' => (int) ($receivable['id'] ?? 0),
    ]);
    $originalCustomerAllocationId = (int) $allocationLookup->fetchColumn();
    $splitFirstAllocation = $db->prepare(
        'UPDATE customer_receipt_allocations
         SET allocated_amount = 725.00,
             receivable_amount_allocated = 725.00,
             payment_amount_consumed = 725.00
         WHERE id = :allocation_id'
    );
    $splitFirstAllocation->execute(['allocation_id' => $originalCustomerAllocationId]);
    $splitSecondAllocation = $db->prepare(
        'INSERT INTO customer_receipt_allocations (
            customer_receipt_id, customer_receivable_item_id, allocated_amount, receivable_currency,
            receivable_amount_allocated, payment_currency, payment_amount_consumed, allocation_note,
            exchange_rate_used, rate_from_currency, rate_to_currency, exchange_rate, exchange_rate_effective_date,
            created_by_user_id
         )
         SELECT customer_receipt_id, customer_receivable_item_id, 25.00, receivable_currency,
                25.00, payment_currency, 25.00, "Second allocation row for cumulative release regression",
                exchange_rate_used, rate_from_currency, rate_to_currency, exchange_rate, exchange_rate_effective_date,
                created_by_user_id
         FROM customer_receipt_allocations
         WHERE id = :allocation_id'
    );
    $splitSecondAllocation->execute(['allocation_id' => $originalCustomerAllocationId]);

    $supplierTreasury = $treasuryRepository->defaultTreasuryAccountForPayment(2, 'AED', 'cash') ?? [];
    $supplierPaymentNo = 'SPAY-PRICE-' . date('YmdHis') . '-' . random_int(1000, 9999);
    $supplierPaymentId = $supplierRepository->createSupplierPayment([
        'supplier_id' => (int) ($obligation['supplier_id'] ?? 0),
        'branch_id' => 2,
        'booking_reference' => $bookingReference,
        'payment_no' => $supplierPaymentNo,
        'payment_date' => $today,
        'currency' => 'AED',
        'payment_scope' => 'booking',
        'paid_amount' => 490.00,
        'payment_method' => 'cash',
        'treasury_account_id' => (int) ($supplierTreasury['id'] ?? 0),
        'reference_number' => 'PRICE-CORRECTION-SUPPLIER-PAYMENT',
        'charges_amount' => 0.0,
        'status' => 'paid',
        'exchange_rate_to_booking' => 1.0,
        'remarks' => 'Full supplier payment before price corrections',
        'actor_user_id' => $actorUserId,
    ]);
    $accountingRepository->postSupplierPaymentRecorded([
        'branch_id' => 2,
        'booking_reference' => $bookingReference,
        'payment_no' => $supplierPaymentNo,
        'supplier_payment_id' => $supplierPaymentId,
        'paid_amount' => 490.00,
        'charges_amount' => 0.0,
        'payment_method' => 'cash',
        'treasury_account_id' => (int) ($supplierTreasury['id'] ?? 0),
        'entry_date' => $today,
        'currency' => 'AED',
        'actor_user_id' => $actorUserId,
        'narration' => 'Full supplier payment before price corrections',
    ]);
    $supplierAllocationId = $supplierRepository->allocateSupplierPayment(
        $supplierPaymentId,
        (int) ($obligation['id'] ?? 0),
        490.00,
        null,
        'Full allocation before price corrections',
        $actorUserId
    );
    $accountingRepository->postSupplierPaymentAllocation([
        'branch_id' => 2,
        'booking_reference' => $bookingReference,
        'source_reference' => $supplierPaymentNo . '-ALLOC-' . $supplierAllocationId,
        'service_line_reference' => $lineReference,
        'supplier_obligation_id' => (int) ($obligation['id'] ?? 0),
        'supplier_payment_id' => $supplierPaymentId,
        'allocated_amount' => 490.00,
        'entry_date' => $today,
        'currency' => 'AED',
        'actor_user_id' => $actorUserId,
        'narration' => 'Full supplier allocation before price corrections',
    ]);

    $serviceWorkspace->correctFinancials([
        'booking_id' => $bookingId,
        'service_id' => $serviceId,
        'corrected_cost_basis' => '380.00',
        'corrected_service_charge' => '370.00',
        'corrected_discount_amount' => '0.00',
        'financial_correction_date' => $today,
        'financial_correction_reason' => 'BK-000420 same-total price mix regression',
    ], $actorUserId, $accessibleBranchIds);

    $sameTotalService = $serviceRepository->findServiceById($serviceId) ?? [];
    $sameTotalReceivable = $customerRepository->findReceivableByServiceLine($bookingReference, $lineReference) ?? [];
    $sameTotalObligation = $supplierRepository->findObligationByServiceLine($bookingReference, $lineReference) ?? [];
    $sameTotalReceipt = $customerRepository->findReceiptById($receiptId) ?? [];
    $sameTotalSupplierPayment = $supplierRepository->findSupplierPaymentById($supplierPaymentId) ?? [];
    $sameTotalPosition = $moneyPosition($bookingReference, $lineReference);

    $check(
        'Same-total correction synchronizes both cost fields and increases service charge',
        abs((float) ($sameTotalService['sale_price'] ?? 0) - 380.00) <= 0.005
        && abs((float) ($sameTotalService['purchase_cost'] ?? 0) - 380.00) <= 0.005
        && abs((float) ($sameTotalService['service_charge'] ?? 0) - 370.00) <= 0.005
        && abs((float) ($sameTotalService['final_sale_price'] ?? 0) - 750.00) <= 0.005
        && abs((float) ($sameTotalService['net_profit_loss'] ?? 0) - 370.00) <= 0.005
    );
    $check(
        'Unchanged customer total preserves its full historical receipt allocation',
        abs((float) ($sameTotalReceivable['due_amount'] ?? 0) - 750.00) <= 0.005
        && abs((float) ($sameTotalReceivable['allocated_amount'] ?? 0) - 750.00) <= 0.005
        && abs((float) ($sameTotalReceivable['outstanding_amount'] ?? 0)) <= 0.005
        && abs((float) ($sameTotalReceipt['received_amount'] ?? 0) - 750.00) <= 0.005
        && abs((float) ($sameTotalReceipt['unallocated_amount'] ?? 0)) <= 0.005
    );
    $check(
        'Reduced supplier cost releases only the excess payment as supplier credit',
        abs((float) ($sameTotalObligation['gross_amount'] ?? 0) - 380.00) <= 0.005
        && abs((float) ($sameTotalObligation['net_payable_amount'] ?? 0)) <= 0.005
        && abs($supplierPaymentAllocation($supplierPaymentId, (int) $sameTotalObligation['id']) - 380.00) <= 0.005
        && abs((float) ($sameTotalSupplierPayment['paid_amount'] ?? 0) - 490.00) <= 0.005
        && abs((float) ($sameTotalSupplierPayment['unallocated_amount'] ?? 0) - 110.00) <= 0.005
    );
    $check(
        'Same-total correction updates recognized cost without changing revenue',
        abs((float) ($sameTotalPosition['SERVICE_REVENUE'] ?? 0) + 750.00) <= 0.005
        && abs((float) ($sameTotalPosition['SERVICE_COST'] ?? 0) - 380.00) <= 0.005,
        json_encode($sameTotalPosition, JSON_UNESCAPED_SLASHES)
    );

    $serviceWorkspace->correctFinancials([
        'booking_id' => $bookingId,
        'service_id' => $serviceId,
        'corrected_cost_basis' => '430.00',
        'corrected_service_charge' => '400.00',
        'corrected_discount_amount' => '0.00',
        'financial_correction_date' => $today,
        'financial_correction_reason' => 'Increase invoice and supplier cost after payment',
    ], $actorUserId, $accessibleBranchIds);

    $increasedReceivable = $customerRepository->findReceivableByServiceLine($bookingReference, $lineReference) ?? [];
    $increasedObligation = $supplierRepository->findObligationByServiceLine($bookingReference, $lineReference) ?? [];
    $increasedSupplierPayment = $supplierRepository->findSupplierPaymentById($supplierPaymentId) ?? [];
    $increasedPosition = $moneyPosition($bookingReference, $lineReference);
    $check(
        'Price increase preserves paid history and creates only the additional customer balance',
        abs((float) ($increasedReceivable['due_amount'] ?? 0) - 830.00) <= 0.005
        && abs((float) ($increasedReceivable['allocated_amount'] ?? 0) - 750.00) <= 0.005
        && abs((float) ($increasedReceivable['outstanding_amount'] ?? 0) - 80.00) <= 0.005
        && abs((float) ($increasedPosition['AR_CONTROL'] ?? 0) - 80.00) <= 0.005
        && abs((float) ($increasedPosition['SERVICE_REVENUE'] ?? 0) + 830.00) <= 0.005
    );
    $check(
        'Supplier cost increase reuses existing unallocated supplier payment before showing payable',
        abs((float) ($increasedObligation['gross_amount'] ?? 0) - 430.00) <= 0.005
        && abs((float) ($increasedObligation['net_payable_amount'] ?? 0)) <= 0.005
        && abs($supplierPaymentAllocation($supplierPaymentId, (int) $increasedObligation['id']) - 430.00) <= 0.005
        && abs((float) ($increasedSupplierPayment['unallocated_amount'] ?? 0) - 60.00) <= 0.005
        && abs((float) ($increasedPosition['SERVICE_COST'] ?? 0) - 430.00) <= 0.005
    );

    $serviceWorkspace->correctFinancials([
        'booking_id' => $bookingId,
        'service_id' => $serviceId,
        'corrected_cost_basis' => '350.00',
        'corrected_service_charge' => '350.00',
        'corrected_discount_amount' => '0.00',
        'financial_correction_date' => $today,
        'financial_correction_reason' => 'Reduce invoice and supplier cost below allocated payments',
    ], $actorUserId, $accessibleBranchIds);

    $reducedService = $serviceRepository->findServiceById($serviceId) ?? [];
    $reducedReceivable = $customerRepository->findReceivableByServiceLine($bookingReference, $lineReference) ?? [];
    $reducedObligation = $supplierRepository->findObligationByServiceLine($bookingReference, $lineReference) ?? [];
    $reducedReceipt = $customerRepository->findReceiptById($receiptId) ?? [];
    $reducedSupplierPayment = $supplierRepository->findSupplierPaymentById($supplierPaymentId) ?? [];
    $reducedPosition = $moneyPosition($bookingReference, $lineReference);

    $check(
        'Price decrease releases excess customer allocation as available credit without changing cash received',
        abs((float) ($reducedService['final_sale_price'] ?? 0) - 700.00) <= 0.005
        && abs((float) ($reducedReceivable['due_amount'] ?? 0) - 700.00) <= 0.005
        && abs((float) ($reducedReceivable['allocated_amount'] ?? 0) - 700.00) <= 0.005
        && abs((float) ($reducedReceivable['outstanding_amount'] ?? 0)) <= 0.005
        && abs((float) ($reducedReceipt['received_amount'] ?? 0) - 750.00) <= 0.005
        && abs((float) ($reducedReceipt['unallocated_amount'] ?? 0) - 50.00) <= 0.005
    );
    $check(
        'Supplier cost decrease releases excess supplier settlement without changing cash paid',
        abs((float) ($reducedObligation['gross_amount'] ?? 0) - 350.00) <= 0.005
        && abs((float) ($reducedObligation['net_payable_amount'] ?? 0)) <= 0.005
        && abs($supplierPaymentAllocation($supplierPaymentId, (int) $reducedObligation['id']) - 350.00) <= 0.005
        && abs((float) ($reducedSupplierPayment['paid_amount'] ?? 0) - 490.00) <= 0.005
        && abs((float) ($reducedSupplierPayment['unallocated_amount'] ?? 0) - 140.00) <= 0.005,
        json_encode([
            'obligation' => $reducedObligation,
            'payment' => $reducedSupplierPayment,
            'payment_allocation' => $supplierPaymentAllocation($supplierPaymentId, (int) ($reducedObligation['id'] ?? 0)),
        ], JSON_UNESCAPED_SLASHES)
    );
    $check(
        'Final journals equal corrected invoice and cost after all allocation consequences',
        abs((float) ($reducedPosition['AR_CONTROL'] ?? 0)) <= 0.005
        && abs((float) ($reducedPosition['AP_CONTROL'] ?? 0)) <= 0.005
        && abs((float) ($reducedPosition['SERVICE_REVENUE'] ?? 0) + 700.00) <= 0.005
        && abs((float) ($reducedPosition['SERVICE_COST'] ?? 0) - 350.00) <= 0.005,
        json_encode($reducedPosition, JSON_UNESCAPED_SLASHES)
    );

    $correctionStatement = $db->prepare(
        'SELECT COUNT(*)
         FROM service_financial_corrections
         WHERE booking_service_id = :service_id
           AND ABS(new_sale_price - new_purchase_cost) <= 0.005'
    );
    $correctionStatement->execute(['service_id' => $serviceId]);
    $check(
        'Every new non-air correction audit snapshot keeps the legacy cost mirror synchronized',
        (int) $correctionStatement->fetchColumn() === 3
    );
} catch (Throwable $exception) {
    $failures[] = $exception::class . ': ' . $exception->getMessage();
    echo '[FAIL] Unexpected exception - ' . $exception::class . ': ' . $exception->getMessage() . PHP_EOL;
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
}

echo PHP_EOL;
if ($failures !== []) {
    echo 'Service financial price correction regression failed:' . PHP_EOL;
    foreach ($failures as $failure) {
        echo ' - ' . $failure . PHP_EOL;
    }
    exit(1);
}

echo 'Service financial price correction regression passed.' . PHP_EOL;
