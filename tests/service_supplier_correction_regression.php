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
$serviceRepository = new \App\Repositories\BookingServiceRepository($app);
$supplierRepository = new \App\Repositories\SupplierRepository($app);
$accountingRepository = new \App\Repositories\AccountingRepository($app);
$reportRepository = new \App\Repositories\ReportRepository($app);
$treasuryRepository = new \App\Repositories\TreasuryRepository($app);

$actorUserId = 1;
$accessibleBranchIds = [1, 2];
$today = date('Y-m-d');
$suffix = date('YmdHis') . '-' . random_int(1000, 9999);
$oldSupplierName = 'Supplier Correction OLD ' . $suffix;
$newSupplierName = 'Supplier Correction NEW ' . $suffix;
$failures = [];

$check = static function (string $label, bool $passed, string $details = '') use (&$failures): void {
    echo ($passed ? '[PASS] ' : '[FAIL] ') . $label . ($details !== '' ? ' - ' . $details : '') . PHP_EOL;
    if (! $passed) {
        $failures[] = $label . ($details !== '' ? ': ' . $details : '');
    }
};

$serviceInput = static function (int $bookingId, int $serviceId, string $supplierName, string $passenger): array {
    return [
        'booking_id' => $bookingId,
        'service_id' => $serviceId,
        'service_type' => 'visa',
        'service_status' => 'Open',
        'currency' => 'AED',
        'cost_currency' => 'AED',
        'supplier_name' => $supplierName,
        'service_passenger_name' => $passenger,
        'sale_price' => '1000.00',
        'purchase_cost' => '1000.00',
        'vat' => '0.00',
        'service_charge' => '0.00',
        'discount_amount' => '0.00',
        'final_sale_price' => '1000.00',
        'remarks' => 'Rollback-only supplier correction regression',
        'visa_country' => 'UAE',
        'visa_type' => 'Visit Visa',
        'visa_remarks' => 'Rollback-only supplier correction regression',
    ];
};

$paymentAllocationTotal = static function (int $paymentId, int $obligationId) use ($db): float {
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

echo 'Service supplier correction regression' . PHP_EOL;
echo 'Started: ' . date(DATE_ATOM) . PHP_EOL . PHP_EOL;

$db->beginTransaction();

try {
    $bookingResult = $bookingWorkspace->saveBooking([
        'branch_id' => 2,
        'booking_status' => 'draft',
        'booking_date' => $today,
        'party_label' => 'Lead Traveler / Booking Party',
        'lead_traveler_name' => 'Supplier Correction Regression Customer',
        'contact_mobile' => '03001234567',
        'remarks' => 'Temporary supplier correction regression booking',
    ], $actorUserId, $accessibleBranchIds);

    $booking = $bookingResult['booking'] ?? [];
    $bookingId = (int) ($booking['id'] ?? 0);
    $bookingReference = (string) ($booking['booking_reference'] ?? '');

    // Case 1: unpaid payable moves directly to the corrected supplier.
    $unpaidCreate = $serviceWorkspace->saveService(
        $serviceInput($bookingId, 0, $oldSupplierName, 'Unpaid Supplier Correction Passenger'),
        $actorUserId,
        $accessibleBranchIds
    );
    $unpaidService = $unpaidCreate['service'] ?? [];
    $unpaidServiceId = (int) ($unpaidService['id'] ?? 0);
    $unpaidLineReference = (string) ($unpaidService['line_reference'] ?? '');
    $unpaidOldObligation = $supplierRepository->findObligationByServiceLine($bookingReference, $unpaidLineReference) ?? [];
    $oldSupplierId = (int) ($unpaidOldObligation['supplier_id'] ?? 0);

    $unpaidUpdate = $serviceWorkspace->saveService(
        $serviceInput($bookingId, $unpaidServiceId, $newSupplierName, 'Unpaid Supplier Correction Passenger'),
        $actorUserId,
        $accessibleBranchIds
    );
    $unpaidCorrectedService = $serviceRepository->findServiceById($unpaidServiceId) ?? [];
    $unpaidCorrectedObligation = $supplierRepository->findObligationByServiceLine($bookingReference, $unpaidLineReference) ?? [];
    $newSupplierId = (int) ($unpaidCorrectedObligation['supplier_id'] ?? 0);

    $check(
        'Unpaid invoice service and payable move to the corrected supplier',
        $oldSupplierId > 0
        && $newSupplierId > 0
        && $newSupplierId !== $oldSupplierId
        && (int) ($unpaidCorrectedService['supplier_id'] ?? 0) === $newSupplierId
        && abs((float) ($unpaidCorrectedObligation['gross_amount'] ?? 0) - 1000.00) <= 0.005
        && abs((float) ($unpaidCorrectedObligation['net_payable_amount'] ?? 0) - 1000.00) <= 0.005,
        json_encode($unpaidUpdate['debug']['supplierCorrection'] ?? [], JSON_UNESCAPED_SLASHES)
    );

    // Case 2: paid cash stays with the prior supplier as reusable credit.
    $paidCreate = $serviceWorkspace->saveService(
        $serviceInput($bookingId, 0, $oldSupplierName, 'Paid Supplier Correction Passenger'),
        $actorUserId,
        $accessibleBranchIds
    );
    $paidService = $paidCreate['service'] ?? [];
    $paidServiceId = (int) ($paidService['id'] ?? 0);
    $paidLineReference = (string) ($paidService['line_reference'] ?? '');
    $paidObligation = $supplierRepository->findObligationByServiceLine($bookingReference, $paidLineReference) ?? [];
    $paidObligationId = (int) ($paidObligation['id'] ?? 0);
    $oldSupplierId = (int) ($paidObligation['supplier_id'] ?? 0);

    $treasury = $treasuryRepository->defaultTreasuryAccountForPayment(2, 'AED', 'cash') ?? [];
    if ((int) ($treasury['id'] ?? 0) <= 0) {
        throw new RuntimeException('An AED cash treasury account is required for this regression.');
    }

    $paymentNo = 'SPAY-SUPCORR-' . $suffix;
    $paymentId = $supplierRepository->createSupplierPayment([
        'supplier_id' => $oldSupplierId,
        'branch_id' => 2,
        'booking_reference' => $bookingReference,
        'payment_no' => $paymentNo,
        'payment_date' => $today,
        'currency' => 'AED',
        'payment_scope' => 'booking',
        'paid_amount' => 600.00,
        'payment_method' => 'cash',
        'treasury_account_id' => (int) $treasury['id'],
        'reference_number' => 'SUPPLIER-CORRECTION-REGRESSION',
        'charges_amount' => 0.0,
        'status' => 'paid',
        'exchange_rate_to_booking' => 1.0,
        'remarks' => 'Partial payment before supplier correction',
        'actor_user_id' => $actorUserId,
    ]);
    $accountingRepository->postSupplierPaymentRecorded([
        'branch_id' => 2,
        'booking_reference' => $bookingReference,
        'payment_no' => $paymentNo,
        'supplier_payment_id' => $paymentId,
        'paid_amount' => 600.00,
        'charges_amount' => 0.0,
        'payment_method' => 'cash',
        'treasury_account_id' => (int) $treasury['id'],
        'entry_date' => $today,
        'currency' => 'AED',
        'actor_user_id' => $actorUserId,
        'narration' => 'Partial supplier payment before supplier correction',
    ]);
    $allocationId = $supplierRepository->allocateSupplierPayment(
        $paymentId,
        $paidObligationId,
        600.00,
        null,
        'Partial allocation before supplier correction',
        $actorUserId
    );
    $accountingRepository->postSupplierPaymentAllocation([
        'branch_id' => 2,
        'booking_reference' => $bookingReference,
        'source_reference' => $paymentNo . '-ALLOC-' . $allocationId,
        'service_line_reference' => $paidLineReference,
        'supplier_obligation_id' => $paidObligationId,
        'supplier_payment_id' => $paymentId,
        'allocated_amount' => 600.00,
        'entry_date' => $today,
        'currency' => 'AED',
        'actor_user_id' => $actorUserId,
        'narration' => 'Partial allocation before supplier correction',
    ]);

    $paidUpdate = $serviceWorkspace->saveService(
        $serviceInput($bookingId, $paidServiceId, $newSupplierName, 'Paid Supplier Correction Passenger'),
        $actorUserId,
        $accessibleBranchIds
    );

    $correctedService = $serviceRepository->findServiceById($paidServiceId) ?? [];
    $correctedObligation = $supplierRepository->findObligationByServiceLine($bookingReference, $paidLineReference) ?? [];
    $correctedPayment = $supplierRepository->findSupplierPaymentById($paymentId) ?? [];
    $supplierCorrection = $paidUpdate['debug']['supplierCorrection'] ?? [];

    $check(
        'Corrected payable belongs only to the new supplier and is fully due',
        (int) ($correctedService['supplier_id'] ?? 0) === $newSupplierId
        && (int) ($correctedObligation['supplier_id'] ?? 0) === $newSupplierId
        && abs((float) ($correctedObligation['gross_amount'] ?? 0) - 1000.00) <= 0.005
        && abs((float) ($correctedObligation['advance_applied_amount'] ?? 0)) <= 0.005
        && abs((float) ($correctedObligation['net_payable_amount'] ?? 0) - 1000.00) <= 0.005
    );

    $check(
        'Prior supplier payment is preserved as reusable unallocated credit',
        (int) ($correctedPayment['supplier_id'] ?? 0) === $oldSupplierId
        && abs((float) ($correctedPayment['paid_amount'] ?? 0) - 600.00) <= 0.005
        && abs((float) ($correctedPayment['allocated_amount'] ?? 0)) <= 0.005
        && abs((float) ($correctedPayment['unallocated_amount'] ?? 0) - 600.00) <= 0.005
        && abs($paymentAllocationTotal($paymentId, $paidObligationId)) <= 0.005
    );

    $check(
        'Supplier correction audit result identifies the released settlement',
        ($supplierCorrection['changed'] ?? false) === true
        && (int) ($supplierCorrection['prior_supplier_id'] ?? 0) === $oldSupplierId
        && (int) ($supplierCorrection['new_supplier_id'] ?? 0) === $newSupplierId
        && abs((float) ($supplierCorrection['released_settlement_amount'] ?? 0) - 600.00) <= 0.005
        && abs((float) ($supplierCorrection['released_payment_amount'] ?? 0) - 600.00) <= 0.005
    );

    $payableAgingRows = $reportRepository->payableAging([2], $today, null, null, $bookingReference);
    $correctedAgingRow = array_values(array_filter(
        $payableAgingRows,
        static fn (array $row): bool => (string) ($row['service_line_reference'] ?? '') === $paidLineReference
    ))[0] ?? [];
    $check(
        'Payable Aging reports the corrected supplier and exact open balance',
        (string) ($correctedAgingRow['supplier_name'] ?? '') === $newSupplierName
        && abs((float) ($correctedAgingRow['gross_amount'] ?? 0) - 1000.00) <= 0.005
        && abs((float) ($correctedAgingRow['net_payable_amount'] ?? 0) - 1000.00) <= 0.005
    );

    $supplierLedgerRows = $reportRepository->supplierLedger(
        [2],
        null,
        null,
        'AED',
        '',
        0,
        0,
        $bookingReference
    );
    $newSupplierInvoiceVisible = false;
    $oldSupplierPaymentVisible = false;
    foreach ($supplierLedgerRows as $ledgerRow) {
        if (
            (string) ($ledgerRow['service_line_reference'] ?? '') === $paidLineReference
            && (string) ($ledgerRow['entry_type'] ?? '') === 'Payable Created'
            && (string) ($ledgerRow['supplier_name'] ?? '') === $newSupplierName
        ) {
            $newSupplierInvoiceVisible = true;
        }
        if (
            (int) ($ledgerRow['supplier_payment_id'] ?? 0) === $paymentId
            && (string) ($ledgerRow['supplier_name'] ?? '') === $oldSupplierName
        ) {
            $oldSupplierPaymentVisible = true;
        }
    }
    $check(
        'Supplier Ledger shows the new supplier invoice but preserves old supplier payment history',
        $newSupplierInvoiceVisible && $oldSupplierPaymentVisible,
        json_encode(array_map(static fn (array $row): array => [
            'supplier' => $row['supplier_name'] ?? null,
            'type' => $row['entry_type'] ?? null,
            'line' => $row['service_line_reference'] ?? null,
            'payment_id' => $row['supplier_payment_id'] ?? null,
        ], $supplierLedgerRows), JSON_UNESCAPED_SLASHES)
    );

    $journalBalanceStatement = $db->prepare(
        'SELECT ROUND(COALESCE(SUM(jel.debit_amount), 0) - COALESCE(SUM(jel.credit_amount), 0), 2)
         FROM journal_entries je
         INNER JOIN journal_entry_lines jel ON jel.journal_entry_id = je.id
         WHERE je.booking_reference = :booking_reference'
    );
    $journalBalanceStatement->execute(['booking_reference' => $bookingReference]);
    $check(
        'All supplier-correction journal entries remain balanced',
        abs((float) $journalBalanceStatement->fetchColumn()) <= 0.005
    );
} catch (Throwable $exception) {
    $failures[] = $exception->getMessage();
    echo '[FAIL] Regression execution - ' . $exception->getMessage() . PHP_EOL;
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
}

if ($failures !== []) {
    echo PHP_EOL . 'Service supplier correction regression failed:' . PHP_EOL;
    foreach ($failures as $failure) {
        echo ' - ' . $failure . PHP_EOL;
    }
    exit(1);
}

echo PHP_EOL . 'Service supplier correction regression passed.' . PHP_EOL;
