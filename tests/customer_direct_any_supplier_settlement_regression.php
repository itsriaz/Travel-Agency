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
    $supplierRepository,
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
        'contact_mobile' => '',
        'passport_number' => '',
        'remarks' => 'Rollback-only direct customer-to-any-supplier settlement regression',
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
        'pricing_exchange_rate' => '1.00000000',
        'pricing_rate_effective_date' => $today,
        'service_charge_currency' => 'PKR',
        'service_charge_exchange_rate' => '1.00000000',
        'service_charge_rate_effective_date' => $today,
        'supplier_name' => $supplier,
        'service_passenger_name' => $customer,
        'sale_price' => number_format($purchaseCost, 2, '.', ''),
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
        'remarks' => 'Rollback-only direct settlement test',
        'ticket_pnr' => $ticketPrefix . substr(hash('crc32b', $customer . $suffix), 0, 5),
        'ticket_number' => $ticketPrefix . '-' . substr($suffix, -8),
        'ticket_airline' => $ticketPrefix,
        'ticket_sector_from' => 'ISB',
        'ticket_sector_to' => 'DXB',
        'ticket_departure_date' => $today,
        'ticket_return_date' => '',
        'ticket_class' => 'Economy',
        'ticket_fare' => number_format($purchaseCost, 2, '.', ''),
        'ticket_tax' => '0.00',
        'ticket_vat' => '0.00',
        'ticket_commission' => '0.00',
        'ticket_remarks' => 'Rollback-only direct settlement test',
    ], $actorUserId, $accessibleBranchIds);
    $service = (array) ($serviceResult['service'] ?? []);
    $lineReference = (string) ($service['line_reference'] ?? '');
    return [
        'booking_id' => $bookingId,
        'booking_reference' => $bookingReference,
        'service_id' => (int) ($service['id'] ?? 0),
        'line_reference' => $lineReference,
        'supplier_id' => (int) ($service['supplier_id'] ?? 0),
        'obligation' => $supplierRepository->findObligationByServiceLine($bookingReference, $lineReference),
    ];
};

echo 'Customer direct payment to any supplier regression' . PHP_EOL;
echo 'Mode: ROLLBACK ONLY (no test booking, payment, receipt, or journal data will remain)' . PHP_EOL . PHP_EOL;

$db->beginTransaction();

try {
    $source = $createBookingService(
        'DIRECT CUSTOMER ' . $suffix,
        'ORIGINAL AIRLINE ' . $suffix,
        1000.00,
        900.00,
        'ORG'
    );
    $target = $createBookingService(
        'OTHER CUSTOMER ' . $suffix,
        'TARGET XYZ ' . $suffix,
        1600.00,
        1500.00,
        'XYZ'
    );

    $sourceReceivable = $customerRepository->findReceivableByServiceLine(
        $source['booking_reference'],
        $source['line_reference']
    );
    $sourceObligation = (array) ($source['obligation'] ?? []);
    $targetObligation = (array) ($target['obligation'] ?? []);

    $check(
        'Source customer PKR 1,000 receivable and unrelated target PKR 1,500 payable exist',
        round((float) ($sourceReceivable['outstanding_amount'] ?? 0), 2) === 1000.00
            && round((float) ($targetObligation['net_payable_amount'] ?? 0), 2) === 1500.00
            && (int) ($sourceObligation['supplier_id'] ?? 0) !== (int) ($targetObligation['supplier_id'] ?? 0),
        json_encode(['receivable' => $sourceReceivable, 'source_ap' => $sourceObligation, 'target_ap' => $targetObligation], JSON_UNESCAPED_SLASHES)
    );

    $reference = 'DIRECT-ANY-SUPPLIER-' . $suffix;
    $result = $receiptWorkspace->saveReceipt([
        'booking_id' => $source['booking_id'],
        'receipt_date' => $today,
        'receipt_currency' => 'PKR',
        'received_amount' => '1000.00',
        'payment_method' => 'customer_paid_supplier',
        'direct_supplier_obligation_id' => (string) ($targetObligation['id'] ?? 0),
        'charges_amount' => '0.00',
        'receipt_status' => 'received',
        'reference_number' => $reference,
        'receipt_remarks' => 'Customer paid unrelated supplier XYZ directly',
    ], $actorUserId, $accessibleBranchIds);

    $receipt = (array) ($result['receipt'] ?? []);
    $sourceReceivableAfter = $customerRepository->findReceivableByServiceLine(
        $source['booking_reference'],
        $source['line_reference']
    );
    $sourceObligationAfter = $supplierRepository->findObligationByServiceLine(
        $source['booking_reference'],
        $source['line_reference']
    );
    $targetObligationAfter = $supplierRepository->findObligationByServiceLine(
        $target['booking_reference'],
        $target['line_reference']
    );

    $check(
        'Source customer receivable closes at PKR 0',
        round((float) ($sourceReceivableAfter['outstanding_amount'] ?? -1), 2) === 0.00
    );
    $check(
        'Chosen unrelated supplier payable falls from PKR 1,500 to PKR 500 and remains partial',
        round((float) ($targetObligationAfter['net_payable_amount'] ?? -1), 2) === 500.00
            && (string) ($targetObligationAfter['status'] ?? '') === 'partially_covered',
        json_encode($targetObligationAfter, JSON_UNESCAPED_SLASHES)
    );
    $check(
        'Original ticket supplier payable remains unchanged at PKR 900',
        round((float) ($sourceObligationAfter['net_payable_amount'] ?? -1), 2) === 900.00,
        json_encode($sourceObligationAfter, JSON_UNESCAPED_SLASHES)
    );
    $check(
        'Customer receipt is fully allocated and has no treasury account',
        (int) ($receipt['id'] ?? 0) > 0
            && round((float) ($receipt['allocated_amount'] ?? 0), 2) === 1000.00
            && round((float) ($receipt['unallocated_amount'] ?? -1), 2) === 0.00
            && ($receipt['treasury_account_id'] ?? null) === null
    );

    $paymentStatement = $db->prepare(
        'SELECT * FROM supplier_payments WHERE reference_number = :reference_number ORDER BY id DESC LIMIT 1'
    );
    $paymentStatement->execute(['reference_number' => $reference]);
    $supplierPayment = (array) ($paymentStatement->fetch(PDO::FETCH_ASSOC) ?: []);
    $check(
        'One non-treasury supplier payment is recorded against target XYZ and its booking',
        (int) ($supplierPayment['supplier_id'] ?? 0) === (int) ($targetObligation['supplier_id'] ?? -1)
            && (string) ($supplierPayment['booking_reference'] ?? '') === (string) $target['booking_reference']
            && in_array((string) ($supplierPayment['payment_method'] ?? ''), ['', 'customer_paid_supplier'], true)
            && round((float) ($supplierPayment['paid_amount'] ?? 0), 2) === 1000.00
            && round((float) ($supplierPayment['allocated_amount'] ?? 0), 2) === 1000.00
            && ($supplierPayment['treasury_account_id'] ?? null) === null,
        json_encode($supplierPayment, JSON_UNESCAPED_SLASHES)
    );

    $journalStatement = $db->prepare(
        'SELECT je.id, coa.code, jel.debit_amount, jel.credit_amount
         FROM journal_entries je
         INNER JOIN journal_entry_lines jel ON jel.journal_entry_id = je.id
         INNER JOIN chart_of_accounts coa ON coa.id = jel.account_id
         WHERE je.source_type = "customer_direct_supplier_payment"
           AND je.booking_reference = :booking_reference
         ORDER BY je.id DESC, jel.id ASC'
    );
    $journalStatement->execute(['booking_reference' => $source['booking_reference']]);
    $journalLines = $journalStatement->fetchAll(PDO::FETCH_ASSOC);
    $journalIds = array_values(array_unique(array_map('intval', array_column($journalLines, 'id'))));
    $debitTotal = round(array_sum(array_map(static fn (array $row): float => (float) $row['debit_amount'], $journalLines)), 2);
    $creditTotal = round(array_sum(array_map(static fn (array $row): float => (float) $row['credit_amount'], $journalLines)), 2);
    $apDebit = round(array_sum(array_map(
        static fn (array $row): float => (string) $row['code'] === 'AP_CONTROL' ? (float) $row['debit_amount'] : 0.0,
        $journalLines
    )), 2);
    $arCredit = round(array_sum(array_map(
        static fn (array $row): float => (string) $row['code'] === 'AR_CONTROL' ? (float) $row['credit_amount'] : 0.0,
        $journalLines
    )), 2);
    $treasuryLines = array_filter(
        $journalLines,
        static fn (array $row): bool => str_starts_with((string) $row['code'], 'CASH_')
            || str_starts_with((string) $row['code'], 'BANK_')
            || in_array((string) $row['code'], ['CASH_ON_HAND', 'BANK_CLEARING'], true)
    );

    $check(
        'Balanced journal posts Dr AP PKR 1,000 and Cr AR PKR 1,000 exactly once',
        count($journalIds) === 1
            && $debitTotal === 1000.00
            && $creditTotal === 1000.00
            && $apDebit === 1000.00
            && $arCredit === 1000.00
    );
    $check(
        'No agency cash/bank journal line is created',
        $treasuryLines === []
    );
} catch (Throwable $exception) {
    $failures[] = $exception->getMessage();
    echo '[FAIL] Scenario execution - ' . $exception->getMessage() . PHP_EOL;
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
}

echo PHP_EOL;
if ($failures !== []) {
    echo 'Customer direct payment to any supplier regression failed:' . PHP_EOL;
    foreach ($failures as $failure) {
        echo ' - ' . $failure . PHP_EOL;
    }
    exit(1);
}

echo 'Customer direct payment to any supplier regression passed.' . PHP_EOL;
