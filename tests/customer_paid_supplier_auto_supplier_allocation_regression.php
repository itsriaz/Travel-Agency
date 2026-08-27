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
$customerRepository = new \App\Repositories\CustomerPaymentRepository($app);
$supplierRepository = new \App\Repositories\SupplierRepository($app);

$branchId = 1;
$actorUserId = 1;
$accessibleBranchIds = [1, 2];
$today = date('Y-m-d');
$suffix = date('YmdHis') . '-' . random_int(1000, 9999);
$failures = [];

$check = static function (string $label, bool $passed, string $details = '') use (&$failures): void {
    echo ($passed ? '[PASS] ' : '[FAIL] ') . $label . ($details !== '' ? ' - ' . $details : '') . PHP_EOL;
    if (! $passed) {
        $failures[] = $label . ($details !== '' ? ': ' . $details : '');
    }
};

$createBookingService = static function (
    string $customer,
    string $supplier,
    float $salePrice,
    float $purchaseCost,
    string $ticketPrefix
) use (
    $bookingWorkspace,
    $serviceWorkspace,
    $branchId,
    $actorUserId,
    $accessibleBranchIds,
    $today,
    $suffix
): array {
    $bookingResult = $bookingWorkspace->saveBooking([
        'branch_id' => $branchId,
        'booking_status' => 'draft',
        'booking_date' => $today,
        'party_label' => 'Lead Traveler / Booking Party',
        'lead_traveler_name' => $customer,
        'remarks' => 'Rollback-only paid supplier automatic supplier allocation regression',
    ], $actorUserId, $accessibleBranchIds);
    $booking = (array) ($bookingResult['booking'] ?? []);

    $serviceResult = $serviceWorkspace->saveService([
        'booking_id' => (int) ($booking['id'] ?? 0),
        'service_type' => 'air ticket',
        'service_status' => 'Open',
        'currency' => 'AED',
        'cost_currency' => 'AED',
        'pricing_exchange_rate' => '1.00000000',
        'pricing_rate_effective_date' => $today,
        'service_charge_currency' => 'AED',
        'service_charge_exchange_rate' => '1.00000000',
        'service_charge_rate_effective_date' => $today,
        'supplier_name' => $supplier,
        'service_passenger_name' => $customer,
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
        'service_charge' => '0.00',
        'discount_amount' => '0.00',
        'final_sale_price' => number_format($salePrice, 2, '.', ''),
        'remarks' => 'Rollback-only paid supplier automatic supplier allocation regression',
        'ticket_pnr' => $ticketPrefix . substr(hash('crc32b', $customer . $suffix), 0, 5),
        'ticket_number' => $ticketPrefix . '-' . substr($suffix, -8),
        'ticket_airline' => $ticketPrefix,
        'ticket_sector_from' => 'DXB',
        'ticket_sector_to' => 'ISB',
        'ticket_departure_date' => $today,
        'ticket_class' => 'Economy',
        'ticket_fare' => number_format($purchaseCost, 2, '.', ''),
        'ticket_tax' => '0.00',
        'ticket_vat' => '0.00',
        'ticket_commission' => '0.00',
    ], $actorUserId, $accessibleBranchIds);
    $service = (array) ($serviceResult['service'] ?? []);

    return [
        'booking_id' => (int) ($booking['id'] ?? 0),
        'booking_reference' => (string) ($booking['booking_reference'] ?? ''),
        'line_reference' => (string) ($service['line_reference'] ?? ''),
        'supplier_id' => (int) ($service['supplier_id'] ?? 0),
    ];
};

echo 'Customer paid supplier automatic supplier invoice allocation regression' . PHP_EOL;
echo 'Mode: ROLLBACK ONLY (no test booking, payment, receipt, or journal data will remain)' . PHP_EOL . PHP_EOL;

$db->beginTransaction();

try {
    $source = $createBookingService('AUTO SUPPLIER CUSTOMER ' . $suffix, 'AUTO SOURCE SUPPLIER ' . $suffix, 500.00, 400.00, 'SRC');
    $targetOne = $createBookingService('SUPP TARGET ONE ' . $suffix, 'AUTO TARGET SUPPLIER ' . $suffix, 300.00, 300.00, 'T1');
    $targetTwo = $createBookingService('SUPP TARGET TWO ' . $suffix, 'AUTO TARGET SUPPLIER ' . $suffix, 200.00, 200.00, 'T2');

    $sourceReceivableBefore = $customerRepository->findReceivableByServiceLine($source['booking_reference'], $source['line_reference']) ?? [];
    $targetOneObligationBefore = $supplierRepository->findObligationByServiceLine($targetOne['booking_reference'], $targetOne['line_reference']) ?? [];
    $targetTwoObligationBefore = $supplierRepository->findObligationByServiceLine($targetTwo['booking_reference'], $targetTwo['line_reference']) ?? [];

    $check(
        'Customer receivable AED 500 and two target supplier invoices AED 300 + AED 200 exist',
        round((float) ($sourceReceivableBefore['outstanding_amount'] ?? 0), 2) === 500.00
            && round((float) ($targetOneObligationBefore['net_payable_amount'] ?? 0), 2) === 300.00
            && round((float) ($targetTwoObligationBefore['net_payable_amount'] ?? 0), 2) === 200.00
            && (int) ($targetOne['supplier_id'] ?? 0) > 0
            && (int) ($targetOne['supplier_id'] ?? 0) === (int) ($targetTwo['supplier_id'] ?? -1)
    );

    $result = $receiptWorkspace->saveReceipt([
        'booking_id' => $source['booking_id'],
        'receipt_date' => $today,
        'receipt_currency' => 'AED',
        'received_amount' => '500.00',
        'payment_method' => 'customer_paid_supplier',
        'direct_supplier_id' => (string) ($targetOne['supplier_id'] ?? 0),
        'charges_amount' => '0.00',
        'receipt_status' => 'received',
        'reference_number' => 'AUTO-SUPP-' . $suffix,
        'receipt_remarks' => 'Auto allocate supplier invoices for the same supplier',
    ], $actorUserId, $accessibleBranchIds);

    $receipt = (array) ($result['receipt'] ?? []);
    $sourceReceivableAfter = $customerRepository->findReceivableByServiceLine($source['booking_reference'], $source['line_reference']) ?? [];
    $targetOneObligationAfter = $supplierRepository->findObligationByServiceLine($targetOne['booking_reference'], $targetOne['line_reference']) ?? [];
    $targetTwoObligationAfter = $supplierRepository->findObligationByServiceLine($targetTwo['booking_reference'], $targetTwo['line_reference']) ?? [];

    $check(
        'Customer receivable fully closes automatically',
        round((float) ($sourceReceivableAfter['outstanding_amount'] ?? -1), 2) === 0.00,
        json_encode($sourceReceivableAfter, JSON_UNESCAPED_SLASHES)
    );
    $check(
        'Both supplier invoices for the chosen supplier are auto-allocated and fully closed',
        round((float) ($targetOneObligationAfter['net_payable_amount'] ?? -1), 2) === 0.00
            && round((float) ($targetTwoObligationAfter['net_payable_amount'] ?? -1), 2) === 0.00,
        json_encode([$targetOneObligationAfter, $targetTwoObligationAfter], JSON_UNESCAPED_SLASHES)
    );
    $check(
        'Customer receipt is fully allocated',
        round((float) ($receipt['allocated_amount'] ?? 0), 2) === 500.00
            && round((float) ($receipt['unallocated_amount'] ?? -1), 2) === 0.00,
        json_encode($receipt, JSON_UNESCAPED_SLASHES)
    );

    $paymentStatement = $db->prepare(
        'SELECT * FROM supplier_payments WHERE reference_number = :reference_number ORDER BY id DESC LIMIT 1'
    );
    $paymentStatement->execute(['reference_number' => 'AUTO-SUPP-' . $suffix]);
    $supplierPayment = (array) ($paymentStatement->fetch(PDO::FETCH_ASSOC) ?: []);
    $check(
        'Single supplier payment was created and fully allocated across both supplier invoices',
        round((float) ($supplierPayment['paid_amount'] ?? 0), 2) === 500.00
            && round((float) ($supplierPayment['allocated_amount'] ?? 0), 2) === 500.00
            && round((float) ($supplierPayment['unallocated_amount'] ?? 0), 2) === 0.00,
        json_encode($supplierPayment, JSON_UNESCAPED_SLASHES)
    );

    $allocationStatement = $db->prepare(
        'SELECT supplier_obligation_id, allocated_amount
         FROM supplier_payment_allocations
         WHERE supplier_payment_id = :payment_id
         ORDER BY id ASC'
    );
    $allocationStatement->execute(['payment_id' => (int) ($supplierPayment['id'] ?? 0)]);
    $paymentAllocations = $allocationStatement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $allocatedTotal = round(array_sum(array_map(static fn (array $row): float => (float) ($row['allocated_amount'] ?? 0), $paymentAllocations)), 2);

    $check(
        'Supplier payment allocation history contains two rows totaling AED 500',
        count($paymentAllocations) === 2 && $allocatedTotal === 500.00,
        json_encode($paymentAllocations, JSON_UNESCAPED_SLASHES)
    );

    $db->rollBack();
} catch (\Throwable $exception) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    throw $exception;
}

if ($failures !== []) {
    fwrite(STDERR, "Customer paid supplier auto supplier allocation regression failed:\n - " . implode("\n - ", $failures) . PHP_EOL);
    exit(1);
}

echo PHP_EOL . '[PASS] Customer paid supplier automatically allocated matching supplier invoices.' . PHP_EOL;
