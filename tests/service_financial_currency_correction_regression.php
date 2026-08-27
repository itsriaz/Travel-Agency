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
$receivableRepository = new \App\Repositories\CustomerPaymentRepository($app);
$supplierRepository = new \App\Repositories\SupplierRepository($app);
$accountingRepository = new \App\Repositories\AccountingRepository($app);
$treasuryRepository = new \App\Repositories\TreasuryRepository($app);
$ledgerService = new \App\Services\SupplierLedgerService($app);

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

$controlBalances = static function (string $bookingReference, string $lineReference) use ($db): array {
    $statement = $db->prepare(
        'SELECT je.currency,
                coa.code,
                ROUND(SUM(jel.debit_amount - jel.credit_amount), 2) AS net_balance
         FROM journal_entries je
         INNER JOIN journal_entry_lines jel ON jel.journal_entry_id = je.id
         INNER JOIN chart_of_accounts coa ON coa.id = jel.account_id
         WHERE je.booking_reference = :booking_reference
           AND jel.service_line_reference = :service_line_reference
           AND coa.code IN ("AR_CONTROL", "SERVICE_REVENUE", "SERVICE_COST", "AP_CONTROL")
         GROUP BY je.currency, coa.code'
    );
    $statement->execute([
        'booking_reference' => $bookingReference,
        'service_line_reference' => $lineReference,
    ]);

    $balances = [];
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $balances[(string) $row['currency']][(string) $row['code']] = round((float) $row['net_balance'], 2);
    }

    return $balances;
};

echo 'Service financial currency correction regression' . PHP_EOL;
echo 'Started: ' . date(DATE_ATOM) . PHP_EOL . PHP_EOL;

$db->beginTransaction();

try {
    $bookingResult = $bookingWorkspace->saveBooking([
        'branch_id' => 2,
        'booking_status' => 'draft',
        'booking_date' => $today,
        'party_label' => 'Lead Traveler / Booking Party',
        'lead_traveler_name' => 'Currency Correction Regression Customer',
        'contact_mobile' => '03001234567',
        'remarks' => 'Temporary currency correction regression',
    ], $actorUserId, $accessibleBranchIds);

    $booking = $bookingResult['booking'] ?? [];
    $bookingId = (int) ($booking['id'] ?? 0);
    $bookingReference = (string) ($booking['booking_reference'] ?? '');

    $serviceResult = $serviceWorkspace->saveService([
        'booking_id' => $bookingId,
        'service_type' => 'air ticket',
        'service_status' => 'Open',
        'currency' => 'AED',
        'cost_currency' => 'AED',
        'supplier_name' => 'Currency Correction Regression Supplier',
        'service_passenger_name' => 'Currency Regression Passenger',
        'sale_price' => '1000.00',
        'purchase_cost' => '1000.00',
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
        'service_charge' => '100.00',
        'discount_amount' => '0.00',
        'remarks' => 'Currency correction regression service',
        'ticket_pnr' => 'CURR01',
        'ticket_number' => 'CURR-REG-001',
        'ticket_airline' => 'PIA',
        'ticket_sector_from' => 'ISB',
        'ticket_sector_to' => 'DXB',
        'ticket_departure_date' => $today,
        'ticket_return_date' => '',
        'ticket_class' => 'Economy',
        'ticket_fare' => '1000.00',
        'ticket_tax' => '0.00',
        'ticket_vat' => '0.00',
        'ticket_commission' => '0.00',
        'ticket_remarks' => 'Currency correction regression service',
    ], $actorUserId, $accessibleBranchIds);

    $service = $serviceResult['service'] ?? [];
    $serviceId = (int) ($service['id'] ?? 0);
    $lineReference = (string) ($service['line_reference'] ?? '');
    $receivableBefore = $receivableRepository->findReceivableByServiceLine($bookingReference, $lineReference);
    $obligationBefore = $supplierRepository->findObligationByServiceLine($bookingReference, $lineReference);

    $check('Temporary AED service and subledgers created',
        $serviceId > 0
        && ($receivableBefore['currency'] ?? '') === 'AED'
        && ($obligationBefore['currency'] ?? '') === 'AED');

    $originalServiceUpdatedAt = (string) ($service['updated_at'] ?? '');
    $touchStatement = $db->prepare(
        'UPDATE booking_services
         SET remarks = CONCAT(remarks, " [stale save touch]"),
             updated_at = DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 5 MINUTE)
         WHERE id = :id'
    );
    $touchStatement->execute(['id' => $serviceId]);

    $staleRejected = false;
    try {
        $serviceWorkspace->saveService([
            'booking_id' => $bookingId,
            'service_id' => $serviceId,
            'service_updated_at' => $originalServiceUpdatedAt,
            'service_type' => 'air ticket',
            'service_status' => 'Open',
            'currency' => 'PKR',
            'cost_currency' => 'PKR',
            'supplier_name' => 'Currency Correction Regression Supplier',
            'service_passenger_name' => 'Currency Regression Passenger',
            'sale_price' => '1000.00',
            'purchase_cost' => '1000.00',
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
            'service_charge' => '100.00',
            'discount_amount' => '0.00',
            'remarks' => 'Currency correction regression service',
            'ticket_pnr' => 'CURR01',
            'ticket_number' => 'CURR-REG-001',
            'ticket_airline' => 'PIA',
            'ticket_sector_from' => 'ISB',
            'ticket_sector_to' => 'DXB',
            'ticket_departure_date' => $today,
            'ticket_return_date' => '',
            'ticket_class' => 'Economy',
            'ticket_fare' => '1000.00',
            'ticket_tax' => '0.00',
            'ticket_vat' => '0.00',
            'ticket_commission' => '0.00',
            'ticket_remarks' => 'Currency correction regression service',
        ], $actorUserId, $accessibleBranchIds);
    } catch (Throwable $staleException) {
        $staleRejected = str_contains($staleException->getMessage(), 'updated elsewhere');
    }
    $check('Stale service save is rejected before it can flip currency back',
        $staleRejected);

    $oldReceivableAmount = round((float) ($receivableBefore['due_amount'] ?? 0), 2);
    $oldPayableAmount = round((float) ($obligationBefore['gross_amount'] ?? 0), 2);

    $receiptResult = $receiptWorkspace->saveReceipt([
        'booking_id' => $bookingId,
        'receipt_date' => $today,
        'receipt_currency' => 'AED',
        'received_amount' => '500.00',
        'payment_method' => 'cash',
        'charges_amount' => '0.00',
        'receipt_status' => 'received',
        'receipt_remarks' => 'Partial receipt before currency correction',
    ], $actorUserId, $accessibleBranchIds);
    $receiptId = (int) ($receiptResult['receipt']['id'] ?? 0);

    $supplierTreasury = $treasuryRepository->defaultTreasuryAccountForPayment(2, 'AED', 'cash') ?? [];
    $supplierPaymentNo = 'SPAY-CURR-' . date('YmdHis') . '-' . random_int(1000, 9999);
    $supplierPaymentId = $supplierRepository->createSupplierPayment([
        'supplier_id' => (int) ($obligationBefore['supplier_id'] ?? 0),
        'branch_id' => 2,
        'booking_reference' => $bookingReference,
        'payment_no' => $supplierPaymentNo,
        'payment_date' => $today,
        'currency' => 'AED',
        'payment_scope' => 'booking',
        'paid_amount' => 400.00,
        'payment_method' => 'cash',
        'treasury_account_id' => (int) ($supplierTreasury['id'] ?? 0),
        'reference_number' => 'CURR-SUP-PAY',
        'bank_card_detail' => null,
        'charges_amount' => 0.0,
        'status' => 'paid',
        'exchange_rate_to_booking' => 1.0,
        'remarks' => 'Partial supplier payment before currency correction',
        'actor_user_id' => $actorUserId,
    ]);
    $accountingRepository->postSupplierPaymentRecorded([
        'branch_id' => 2,
        'booking_reference' => $bookingReference,
        'payment_no' => $supplierPaymentNo,
        'supplier_payment_id' => $supplierPaymentId,
        'paid_amount' => 400.00,
        'charges_amount' => 0.0,
        'payment_method' => 'cash',
        'treasury_account_id' => (int) ($supplierTreasury['id'] ?? 0),
        'entry_date' => $today,
        'currency' => 'AED',
        'actor_user_id' => $actorUserId,
        'narration' => 'Partial supplier payment before currency correction',
    ]);
    $supplierAllocationId = $supplierRepository->allocateSupplierPayment(
        $supplierPaymentId,
        (int) ($obligationBefore['id'] ?? 0),
        400.00,
        null,
        'Partial allocation before currency correction',
        $actorUserId
    );
    $accountingRepository->postSupplierPaymentAllocation([
        'branch_id' => 2,
        'booking_reference' => $bookingReference,
        'source_reference' => $supplierPaymentNo . '-ALLOC-' . $supplierAllocationId,
        'service_line_reference' => $lineReference,
        'supplier_obligation_id' => (int) ($obligationBefore['id'] ?? 0),
        'supplier_payment_id' => $supplierPaymentId,
        'allocated_amount' => 400.00,
        'entry_date' => $today,
        'currency' => 'AED',
        'actor_user_id' => $actorUserId,
        'narration' => 'Partial supplier allocation before currency correction',
    ]);

    $partPaidReceivable = $receivableRepository->findReceivableByServiceLine($bookingReference, $lineReference);
    $partPaidObligation = $supplierRepository->findObligationByServiceLine($bookingReference, $lineReference);
    $check('Partial customer and supplier allocations exist before correction',
        $receiptId > 0
        && abs((float) ($partPaidReceivable['allocated_amount'] ?? 0) - 500.00) <= 0.005
        && abs((float) ($partPaidObligation['net_payable_amount'] ?? 0) - ($oldPayableAmount - 400.00)) <= 0.005);

    $serviceWorkspace->correctFinancials([
        'booking_id' => $bookingId,
        'service_id' => $serviceId,
        'corrected_invoice_currency' => 'PKR',
        'corrected_cost_currency' => 'PKR',
        'corrected_cost_basis' => '1000.00',
        'corrected_final_sale_price' => number_format($oldReceivableAmount, 2, '.', ''),
        'financial_correction_date' => $today,
        'financial_correction_reason' => 'Correct invoice and supplier currency for regression verification',
        'financial_correction_note' => 'Automated transaction rolled back after assertions.',
    ], $actorUserId, $accessibleBranchIds);

    $receivableAfter = $receivableRepository->findReceivableByServiceLine($bookingReference, $lineReference);
    $obligationAfter = $supplierRepository->findObligationByServiceLine($bookingReference, $lineReference);
    $receiptAfter = $receivableRepository->findReceiptById($receiptId);
    $supplierPaymentAfter = $supplierRepository->findSupplierPaymentById($supplierPaymentId);
    $balances = $controlBalances($bookingReference, $lineReference);

    $check('Receivable moved to PKR without changing its amount',
        ($receivableAfter['currency'] ?? '') === 'PKR'
        && abs((float) ($receivableAfter['due_amount'] ?? 0) - $oldReceivableAmount) <= 0.005,
        json_encode($receivableAfter, JSON_UNESCAPED_SLASHES));
    $check('Supplier obligation moved to PKR without changing its amount',
        ($obligationAfter['currency'] ?? '') === 'PKR'
        && abs((float) ($obligationAfter['gross_amount'] ?? 0) - $oldPayableAmount) <= 0.005,
        json_encode($obligationAfter, JSON_UNESCAPED_SLASHES));
    $check('Original AED customer receipt became available customer credit',
        abs((float) ($receiptAfter['allocated_amount'] ?? 0)) <= 0.005
        && abs((float) ($receiptAfter['unallocated_amount'] ?? 0) - 500.00) <= 0.005,
        json_encode($receiptAfter, JSON_UNESCAPED_SLASHES));
    $check('Original AED supplier payment became available supplier advance',
        abs((float) ($supplierPaymentAfter['allocated_amount'] ?? 0)) <= 0.005
        && abs((float) ($supplierPaymentAfter['unallocated_amount'] ?? 0) - 400.00) <= 0.005,
        json_encode($supplierPaymentAfter, JSON_UNESCAPED_SLASHES));
    $check('Original AED AR and revenue positions were fully reversed',
        abs((float) ($balances['AED']['AR_CONTROL'] ?? 0)) <= 0.005
        && abs((float) ($balances['AED']['SERVICE_REVENUE'] ?? 0)) <= 0.005,
        json_encode($balances['AED'] ?? [], JSON_UNESCAPED_SLASHES));
    $check('Original AED cost and AP positions were fully reversed',
        abs((float) ($balances['AED']['SERVICE_COST'] ?? 0)) <= 0.005
        && abs((float) ($balances['AED']['AP_CONTROL'] ?? 0)) <= 0.005,
        json_encode($balances['AED'] ?? [], JSON_UNESCAPED_SLASHES));
    $check('New PKR AR and revenue positions equal the corrected receivable',
        abs((float) ($balances['PKR']['AR_CONTROL'] ?? 0) - $oldReceivableAmount) <= 0.005
        && abs((float) ($balances['PKR']['SERVICE_REVENUE'] ?? 0) + $oldReceivableAmount) <= 0.005,
        json_encode($balances['PKR'] ?? [], JSON_UNESCAPED_SLASHES));
    $check('New PKR cost and AP positions equal the corrected obligation',
        abs((float) ($balances['PKR']['SERVICE_COST'] ?? 0) - $oldPayableAmount) <= 0.005
        && abs((float) ($balances['PKR']['AP_CONTROL'] ?? 0) + $oldPayableAmount) <= 0.005,
        json_encode($balances['PKR'] ?? [], JSON_UNESCAPED_SLASHES));

    $serviceWorkspace->correctFinancials([
        'booking_id' => $bookingId,
        'service_id' => $serviceId,
        'corrected_invoice_currency' => 'PKR',
        'corrected_cost_currency' => 'PKR',
        'corrected_cost_basis' => '1050.00',
        'corrected_final_sale_price' => '1150.00',
        'financial_correction_date' => $today,
        'financial_correction_reason' => 'Verify normal same-currency amount adjustment remains compatible',
    ], $actorUserId, $accessibleBranchIds);
    $sameCurrencyBalances = $controlBalances($bookingReference, $lineReference);
    $check('Same-currency correction still posts only the numerical PKR adjustment',
        abs((float) ($sameCurrencyBalances['PKR']['AR_CONTROL'] ?? 0) - 1150.00) <= 0.005
        && abs((float) ($sameCurrencyBalances['PKR']['SERVICE_REVENUE'] ?? 0) + 1150.00) <= 0.005
        && abs((float) ($sameCurrencyBalances['PKR']['SERVICE_COST'] ?? 0) - 1050.00) <= 0.005
        && abs((float) ($sameCurrencyBalances['PKR']['AP_CONTROL'] ?? 0) + 1050.00) <= 0.005,
        json_encode($sameCurrencyBalances['PKR'] ?? [], JSON_UNESCAPED_SLASHES));
    $ledgerAfterSameCurrencyCorrection = $ledgerService->report([
        'dateFrom' => null,
        'dateTo' => null,
        'currency' => '',
        'airline' => '',
        'supplierId' => (int) ($obligationAfter['supplier_id'] ?? 0),
        'businessSourceId' => 0,
        'bookingReference' => $bookingReference,
    ], [$booking['branch_id']]);
    $sameCurrencyPayableRows = array_values(array_filter(
        (array) ($ledgerAfterSameCurrencyCorrection['rows'] ?? []),
        static fn (array $row): bool =>
            (string) ($row['booking_reference'] ?? '') === $bookingReference
            && (string) ($row['currency'] ?? '') === 'PKR'
            && in_array((string) ($row['source_entry_type'] ?? ''), ['Payable Created', 'Payable Adjustment'], true)
    ));
    $check('Supplier ledger keeps a single live PKR payable row after same-currency adjustments that follow a currency correction',
        count($sameCurrencyPayableRows) === 1
        && abs((float) str_replace(',', '', (string) ($sameCurrencyPayableRows[0]['debit_amount'] ?? '0')) - 1050.00) <= 0.005,
        json_encode($sameCurrencyPayableRows, JSON_UNESCAPED_SLASHES));

    $serviceWorkspace->correctFinancials([
        'booking_id' => $bookingId,
        'service_id' => $serviceId,
        'corrected_invoice_currency' => 'AED',
        'corrected_cost_currency' => 'AED',
        'corrected_cost_basis' => '1050.00',
        'corrected_final_sale_price' => '1150.00',
        'financial_correction_date' => $today,
        'financial_correction_reason' => 'Flip the service back to AED to verify repeated currency corrections stay singular',
    ], $actorUserId, $accessibleBranchIds);

    $ledgerAfterAedFlip = $ledgerService->report([
        'dateFrom' => null,
        'dateTo' => null,
        'currency' => '',
        'airline' => '',
        'supplierId' => (int) ($obligationAfter['supplier_id'] ?? 0),
        'businessSourceId' => 0,
        'bookingReference' => $bookingReference,
    ], [$booking['branch_id']]);
    $ledgerRowsAfterAedFlip = array_values(array_filter(
        (array) ($ledgerAfterAedFlip['rows'] ?? []),
        static fn (array $row): bool => (string) ($row['booking_reference'] ?? '') === $bookingReference
    ));
    $staleAdvanceAdjustmentsAfterAedFlip = array_values(array_filter(
        $ledgerRowsAfterAedFlip,
        static fn (array $row): bool =>
            (string) ($row['source_entry_type'] ?? '') === 'Advance Adjustment'
            && (string) ($row['currency'] ?? '') !== 'AED'
    ));
    $check('Supplier ledger drops stale non-AED advance-adjustment artifacts after flipping back to AED',
        $staleAdvanceAdjustmentsAfterAedFlip === [],
        json_encode($staleAdvanceAdjustmentsAfterAedFlip, JSON_UNESCAPED_SLASHES));

    $serviceWorkspace->correctFinancials([
        'booking_id' => $bookingId,
        'service_id' => $serviceId,
        'corrected_invoice_currency' => 'PKR',
        'corrected_cost_currency' => 'PKR',
        'corrected_cost_basis' => '1050.00',
        'corrected_final_sale_price' => '1150.00',
        'financial_correction_date' => $today,
        'financial_correction_reason' => 'Return the service to PKR after the temporary AED flip',
    ], $actorUserId, $accessibleBranchIds);

    $ledgerReport = $ledgerService->report([
        'dateFrom' => null,
        'dateTo' => null,
        'currency' => '',
        'airline' => '',
        'supplierId' => (int) ($obligationAfter['supplier_id'] ?? 0),
        'businessSourceId' => 0,
        'bookingReference' => $bookingReference,
    ], [$booking['branch_id']]);
    $ledgerRows = array_values(array_filter(
        (array) ($ledgerReport['rows'] ?? []),
        static fn (array $row): bool => (string) ($row['booking_reference'] ?? '') === $bookingReference
    ));
    $payableRows = array_values(array_filter(
        $ledgerRows,
        static fn (array $row): bool => in_array((string) ($row['source_entry_type'] ?? ''), ['Payable Created', 'Payable Adjustment'], true)
    ));
    $staleAdvanceAdjustmentRows = array_values(array_filter(
        $ledgerRows,
        static fn (array $row): bool =>
            (string) ($row['source_entry_type'] ?? '') === 'Advance Adjustment'
            && (string) ($row['currency'] ?? '') !== 'PKR'
    ));
    $totalPayableDebit = 0.0;
    $allPayableRowsArePkr = $payableRows !== [];
    foreach ($payableRows as $payableRow) {
        $totalPayableDebit += (float) str_replace(',', '', (string) ($payableRow['debit_amount'] ?? '0'));
        if ((string) ($payableRow['currency'] ?? '') !== 'PKR') {
            $allPayableRowsArePkr = false;
        }
    }
    $check('Repeated supplier currency corrections keep only PKR live payable movements and preserve the final payable truth',
        $allPayableRowsArePkr
        && count($payableRows) >= 1
        && abs($totalPayableDebit - 1050.00) <= 0.005,
        json_encode($payableRows, JSON_UNESCAPED_SLASHES));
    $check('Supplier ledger drops stale old-currency advance-adjustment artifacts after currency correction',
        $staleAdvanceAdjustmentRows === [],
        json_encode($staleAdvanceAdjustmentRows, JSON_UNESCAPED_SLASHES));

    $reportService = new \App\Services\ReportService($app);
    $screenState = $reportService->reportState([
        'report' => 'supplier_ledger',
        'bookingReference' => $bookingReference,
        'supplierId' => (int) ($obligationAfter['supplier_id'] ?? 0),
        'currency' => '',
        'airline' => '',
        'businessSourceId' => 0,
        'dateFrom' => null,
        'dateTo' => null,
    ], $accessibleBranchIds, $actorUserId, 'screen');
    $printState = $reportService->reportState([
        'report' => 'supplier_ledger',
        'bookingReference' => $bookingReference,
        'supplierId' => (int) ($obligationAfter['supplier_id'] ?? 0),
        'currency' => '',
        'airline' => '',
        'businessSourceId' => 0,
        'dateFrom' => null,
        'dateTo' => null,
    ], $accessibleBranchIds, $actorUserId, 'print');
    $screenColumns = array_map(static fn (array $column): string => (string) ($column['key'] ?? ''), (array) ($screenState['columns'] ?? []));
    $printColumns = array_map(static fn (array $column): string => (string) ($column['key'] ?? ''), (array) ($printState['columns'] ?? []));
    $screenRows = array_values(array_filter(
        (array) ($screenState['rows'] ?? []),
        static fn (array $row): bool => (string) ($row['booking_reference'] ?? '') === $bookingReference
    ));
    $traceRow = null;
    foreach ($screenRows as $row) {
        if (trim((string) ($row['trace'] ?? '')) !== '') {
            $traceRow = $row;
            break;
        }
    }
    $check('Supplier ledger trace is visible only in the on-screen report',
        in_array('trace', $screenColumns, true)
        && ! in_array('trace', $printColumns, true)
        && $traceRow !== null
        && trim((string) ($traceRow['trace'] ?? '')) !== '',
        json_encode([
            'screen_columns' => $screenColumns,
            'print_columns' => $printColumns,
            'trace_row' => $traceRow,
        ], JSON_UNESCAPED_SLASHES));
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
    echo 'Service financial currency correction regression failed:' . PHP_EOL;
    foreach ($failures as $failure) {
        echo ' - ' . $failure . PHP_EOL;
    }
    exit(1);
}

echo 'Service financial currency correction regression passed.' . PHP_EOL;
