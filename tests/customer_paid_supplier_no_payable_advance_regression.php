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
        'remarks' => 'Rollback-only no-payable supplier advance regression',
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
        'remarks' => 'Rollback-only no-payable supplier advance regression',
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
    ];
};

echo 'Customer paid supplier with no open payable becomes supplier advance regression' . PHP_EOL;
echo 'Mode: ROLLBACK ONLY (no test booking, payment, receipt, or journal data will remain)' . PHP_EOL . PHP_EOL;

$db->beginTransaction();

try {
    $source = $createBookingService('NO PAYABLE CUSTOMER ' . $suffix, 'SOURCE TICKET SUPPLIER ' . $suffix, 400.00, 300.00, 'SRC');

    $targetSupplierId = $supplierRepository->registerSupplier([
        'branch_id' => $branchId,
        'code' => $supplierRepository->nextSupplierCode(),
        'name' => 'NO PAYABLE TARGET SUPPLIER ' . $suffix,
        'supplier_mode' => 'normal_payable',
        'default_currency' => 'AED',
        'notes' => 'Rollback-only target supplier with no open payable',
        'actor_user_id' => $actorUserId,
    ]);

    $sourceReceivableBefore = $customerRepository->findReceivableByServiceLine($source['booking_reference'], $source['line_reference']) ?? [];
    $openTargetPayables = $supplierRepository->globalOpenObligations($branchId, $targetSupplierId, 'AED');

    $check(
        'Customer receivable exists and chosen supplier has no open payable',
        round((float) ($sourceReceivableBefore['outstanding_amount'] ?? 0), 2) === 400.00
            && $openTargetPayables === []
    );

    $reference = 'NO-PAYABLE-SUPP-' . $suffix;
    $result = $receiptWorkspace->saveReceipt([
        'booking_id' => $source['booking_id'],
        'receipt_date' => $today,
        'receipt_currency' => 'AED',
        'received_amount' => '400.00',
        'payment_method' => 'customer_paid_supplier',
        'direct_supplier_id' => (string) $targetSupplierId,
        'charges_amount' => '0.00',
        'receipt_status' => 'received',
        'reference_number' => $reference,
        'receipt_remarks' => 'Supplier chosen without open payable',
    ], $actorUserId, $accessibleBranchIds);

    $receipt = (array) ($result['receipt'] ?? []);
    $sourceReceivableAfter = $customerRepository->findReceivableByServiceLine($source['booking_reference'], $source['line_reference']) ?? [];

    $check(
        'Customer receivable fully closes',
        round((float) ($sourceReceivableAfter['outstanding_amount'] ?? -1), 2) === 0.00,
        json_encode($sourceReceivableAfter, JSON_UNESCAPED_SLASHES)
    );
    $check(
        'Customer receipt is fully allocated',
        round((float) ($receipt['allocated_amount'] ?? 0), 2) === 400.00
            && round((float) ($receipt['unallocated_amount'] ?? -1), 2) === 0.00,
        json_encode($receipt, JSON_UNESCAPED_SLASHES)
    );

    $paymentStatement = $db->prepare(
        'SELECT * FROM supplier_payments WHERE reference_number = :reference_number ORDER BY id DESC LIMIT 1'
    );
    $paymentStatement->execute(['reference_number' => $reference]);
    $supplierPayment = (array) ($paymentStatement->fetch(PDO::FETCH_ASSOC) ?: []);
    $check(
        'Supplier payment is converted fully into linked supplier advance',
        (int) ($supplierPayment['supplier_id'] ?? 0) === $targetSupplierId
            && round((float) ($supplierPayment['paid_amount'] ?? 0), 2) === 400.00
            && round((float) ($supplierPayment['allocated_amount'] ?? 0), 2) === 0.00
            && round((float) ($supplierPayment['unallocated_amount'] ?? 0), 2) === 0.00
            && round((float) ($supplierPayment['converted_advance_amount'] ?? 0), 2) === 400.00
            && (int) ($supplierPayment['converted_advance_id'] ?? 0) > 0,
        json_encode($supplierPayment, JSON_UNESCAPED_SLASHES)
    );

    $advanceStatement = $db->prepare(
        'SELECT * FROM supplier_advances WHERE source_supplier_payment_id = :payment_id ORDER BY id DESC LIMIT 1'
    );
    $advanceStatement->execute(['payment_id' => (int) ($supplierPayment['id'] ?? 0)]);
    $supplierAdvance = (array) ($advanceStatement->fetch(PDO::FETCH_ASSOC) ?: []);
    $check(
        'Supplier advance row is created with AED 400 available',
        (int) ($supplierAdvance['supplier_id'] ?? 0) === $targetSupplierId
            && round((float) ($supplierAdvance['deposit_amount'] ?? 0), 2) === 400.00
            && round((float) ($supplierAdvance['available_amount'] ?? 0), 2) === 400.00,
        json_encode($supplierAdvance, JSON_UNESCAPED_SLASHES)
    );

    $allocationStatement = $db->prepare(
        'SELECT COUNT(*) FROM supplier_payment_allocations WHERE supplier_payment_id = :payment_id'
    );
    $allocationStatement->execute(['payment_id' => (int) ($supplierPayment['id'] ?? 0)]);
    $allocationCount = (int) ($allocationStatement->fetchColumn() ?: 0);
    $check(
        'No supplier invoice allocation rows are created',
        $allocationCount === 0
    );

    $journalStatement = $db->prepare(
        'SELECT coa.code, jel.debit_amount, jel.credit_amount
         FROM journal_entries je
         INNER JOIN journal_entry_lines jel ON jel.journal_entry_id = je.id
         INNER JOIN chart_of_accounts coa ON coa.id = jel.account_id
         WHERE je.source_type = "customer_direct_supplier_payment"
           AND je.booking_reference = :booking_reference
         ORDER BY je.id DESC, jel.id ASC'
    );
    $journalStatement->execute(['booking_reference' => $source['booking_reference']]);
    $journalLines = $journalStatement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $advanceDebit = round(array_sum(array_map(
        static fn (array $row): float => (string) ($row['code'] ?? '') === 'SUPPLIER_ADVANCES' ? (float) ($row['debit_amount'] ?? 0) : 0.0,
        $journalLines
    )), 2);
    $arCredit = round(array_sum(array_map(
        static fn (array $row): float => (string) ($row['code'] ?? '') === 'AR_CONTROL' ? (float) ($row['credit_amount'] ?? 0) : 0.0,
        $journalLines
    )), 2);
    $check(
        'Accounting posts Dr Supplier Advances and Cr AR for AED 400',
        $advanceDebit === 400.00 && $arCredit === 400.00,
        json_encode($journalLines, JSON_UNESCAPED_SLASHES)
    );

    $db->rollBack();
} catch (\Throwable $exception) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    throw $exception;
}

if ($failures !== []) {
    fwrite(STDERR, "Customer paid supplier no payable advance regression failed:\n - " . implode("\n - ", $failures) . PHP_EOL);
    exit(1);
}

echo PHP_EOL . '[PASS] Customer paid supplier without open payable becomes supplier advance.' . PHP_EOL;
