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
$supplierSettlement = new \App\Services\SupplierSettlementWorkspaceService($app);
$supplierRepository = new \App\Repositories\SupplierRepository($app);
$supplierLedger = new \App\Services\SupplierLedgerService($app);
$accountLedger = new \App\Services\AccountLedgerService($app);
$reportRepository = new \App\Repositories\ReportRepository($app);
$reportService = new \App\Services\ReportService($app);

$branchId = 1;
$actorUserId = 1;
$accessibleBranchIds = [1, 2];
$today = date('Y-m-d');
$suffix = date('YmdHis') . '-' . random_int(1000, 9999);
$supplierName = 'XYZ REGRESSION ' . $suffix;
$keepData = in_array('--keep', $argv ?? [], true);
$failures = [];

$check = static function (string $label, bool $passed, string $details = '') use (&$failures): void {
    echo ($passed ? '[PASS] ' : '[FAIL] ') . $label . ($details !== '' ? ' - ' . $details : '') . PHP_EOL;
    if (! $passed) {
        $failures[] = $label . ($details !== '' ? ': ' . $details : '');
    }
};

$servicePayload = static function (string $customer, string $supplier, string $suffix, string $today): array {
    return [
        'service_type' => 'air ticket',
        'service_status' => 'Open',
        'currency' => 'PKR',
        'supplier_name' => $supplier,
        'service_passenger_name' => $customer,
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
        'service_charge' => '0.00',
        'discount_amount' => '0.00',
        'remarks' => 'Rollback-only five-ticket supplier-credit scenario',
        'ticket_pnr' => 'XYZ' . substr(hash('crc32b', $customer . $suffix), 0, 5),
        'ticket_number' => 'XYZ-' . $customer . '-' . substr($suffix, -4),
        'ticket_airline' => 'XYZ',
        'ticket_sector_from' => 'ISB',
        'ticket_sector_to' => 'DXB',
        'ticket_departure_date' => $today,
        'ticket_return_date' => '',
        'ticket_class' => 'Economy',
        'ticket_fare' => '1000.00',
        'ticket_tax' => '0.00',
        'ticket_vat' => '0.00',
        'ticket_commission' => '0.00',
        'ticket_remarks' => 'Rollback-only scenario',
    ];
};

$openTotalFor = static function (PDO $db, int $supplierId, array $bookingReferences): float {
    $placeholders = implode(', ', array_fill(0, count($bookingReferences), '?'));
    $statement = $db->prepare(
        "SELECT COALESCE(SUM(net_payable_amount), 0)
         FROM supplier_obligations
         WHERE supplier_id = ?
           AND booking_reference IN ({$placeholders})
           AND status IN ('open', 'partially_covered')"
    );
    $statement->execute(array_merge([$supplierId], $bookingReferences));
    return round((float) $statement->fetchColumn(), 2);
};

$ledgerClosingBalance = static function (array $report): float {
    $rows = (array) ($report['rows'] ?? []);
    if ($rows === []) {
        return 0.0;
    }
    return round((float) ($rows[count($rows) - 1]['raw_balance_amount'] ?? 0), 2);
};

echo 'Five-ticket supplier cancellation/credit scenario' . PHP_EOL;
echo $keepData
    ? 'Mode: KEEP ON PASS (successful XYZ, Riaz, ABC, payment, and ledger data will remain)' . PHP_EOL
    : 'Mode: ROLLBACK ONLY (no XYZ, Riaz, ABC, payment, or ledger data will remain)' . PHP_EOL;
echo 'Assumption: ABC2 is one of the three supplier tickets already paid.' . PHP_EOL . PHP_EOL;

$db->beginTransaction();

try {
    $baseTreasury = $db->query(
        'SELECT * FROM treasury_accounts
         WHERE branch_id = 1 AND currency = "PKR" AND account_type = "cash"
           AND is_active = 1 AND linked_account_id IS NOT NULL
         ORDER BY is_default DESC, id ASC LIMIT 1'
    )->fetch(PDO::FETCH_ASSOC);
    if ($baseTreasury === false) {
        throw new RuntimeException('No active branch-1 PKR cash account exists to clone as rollback-only account Riaz.');
    }

    $insertRiaz = $db->prepare(
        'INSERT INTO treasury_accounts (
            branch_id, linked_account_id, account_type, account_name, account_code, currency,
            bank_name, account_number, iban, opening_balance, opening_balance_date,
            is_default, is_active, notes, created_by_user_id
         ) VALUES (
            :branch_id, :linked_account_id, "cash", "Riaz", :account_code, "PKR",
            NULL, NULL, NULL, 10000.00, :opening_balance_date,
            0, 1, :notes, :created_by_user_id
         )'
    );
    $insertRiaz->execute([
        'branch_id' => $branchId,
        'linked_account_id' => (int) $baseTreasury['linked_account_id'],
        'account_code' => 'REG-RIAZ-' . $suffix,
        'opening_balance_date' => $today,
        'notes' => 'Rollback-only Riaz account funded with PKR 10,000 for five-ticket scenario',
        'created_by_user_id' => $actorUserId,
    ]);
    $riazTreasuryId = (int) $db->lastInsertId();

    $scenario = [];
    $supplierId = 0;
    foreach (['ABC1', 'ABC2', 'ABC3', 'ABC4', 'ABC5'] as $customer) {
        $bookingResult = $bookingWorkspace->saveBooking([
            'branch_id' => $branchId,
            'booking_status' => 'draft',
            'booking_date' => $today,
            'party_label' => 'Lead Traveler / Booking Party',
            'lead_traveler_name' => $customer,
            'contact_mobile' => '',
            'passport_number' => '',
            'remarks' => 'Rollback-only five-ticket supplier-credit scenario',
        ], $actorUserId, $accessibleBranchIds);
        $booking = (array) ($bookingResult['booking'] ?? []);
        $bookingId = (int) ($booking['id'] ?? 0);
        $bookingReference = (string) ($booking['booking_reference'] ?? '');

        $serviceResult = $serviceWorkspace->saveService(
            array_merge(
                ['booking_id' => $bookingId],
                $servicePayload($customer, $supplierName, $suffix, $today)
            ),
            $actorUserId,
            $accessibleBranchIds
        );
        $service = (array) ($serviceResult['service'] ?? []);
        $supplierId = (int) ($service['supplier_id'] ?? $supplierId);
        $obligation = $supplierRepository->findObligationByServiceLine(
            $bookingReference,
            (string) ($service['line_reference'] ?? '')
        );
        $scenario[$customer] = [
            'booking_id' => $bookingId,
            'booking_reference' => $bookingReference,
            'service_id' => (int) ($service['id'] ?? 0),
            'line_reference' => (string) ($service['line_reference'] ?? ''),
            'obligation_id' => (int) ($obligation['id'] ?? 0),
        ];
    }

    $check(
        'Five PKR 1,000 supplier tickets were created for XYZ',
        $supplierId > 0
            && count($scenario) === 5
            && array_sum(array_column($scenario, 'obligation_id')) > 0,
        'gross supplier invoices=PKR 5,000.00'
    );

    $paymentResult = $supplierSettlement->recordGlobalPostpaidSupplierPayment([
        'branch_id' => $branchId,
        'supplier_id' => $supplierId,
        'global_supplier_obligation_id' => [
            $scenario['ABC1']['obligation_id'],
            $scenario['ABC2']['obligation_id'],
            $scenario['ABC3']['obligation_id'],
        ],
        'supplier_payment_currency' => 'PKR',
        'supplier_paid_amount' => '3000.00',
        'supplier_payment_date' => $today,
        'supplier_payment_method' => 'cash',
        'supplier_treasury_account_id' => $riazTreasuryId,
        'supplier_reference_number' => 'XYZ-3000-' . $suffix,
        'supplier_payment_remarks' => 'Paid from account Riaz for ABC1, ABC2, and ABC3',
    ], $actorUserId, $accessibleBranchIds);
    $globalPaymentId = (int) ($paymentResult['payment']['id'] ?? 0);

    $allReferences = array_column($scenario, 'booking_reference');
    $unpaidReferences = [$scenario['ABC4']['booking_reference'], $scenario['ABC5']['booking_reference']];
    $openBeforeCancellation = $openTotalFor($db, $supplierId, $allReferences);
    $check(
        'PKR 3,000 global payment from Riaz fully paid ABC1, ABC2, and ABC3',
        round((float) ($paymentResult['allocated_amount'] ?? 0), 2) === 3000.00
            && (int) ($paymentResult['allocation_count'] ?? 0) === 3
            && $openBeforeCancellation === 2000.00,
        'gross unpaid supplier invoices=PKR ' . number_format($openBeforeCancellation, 2)
    );

    $serviceWorkspace->cancelService([
        'booking_id' => $scenario['ABC2']['booking_id'],
        'service_id' => $scenario['ABC2']['service_id'],
        'cancel_reason' => 'ABC2 ticket cancelled for supplier-credit scenario',
        'cancel_event_date' => $today,
        'cancel_notes' => 'No customer penalty; supplier penalty PKR 600.',
    ], $actorUserId, $accessibleBranchIds);

    $serviceWorkspace->settleCancellationFinancials([
        'booking_id' => $scenario['ABC2']['booking_id'],
        'service_id' => $scenario['ABC2']['service_id'],
        'settlement_reason' => 'Supplier retains PKR 600 and returns PKR 400',
        'settlement_event_date' => $today,
        'customer_penalty_amount' => '0.00',
        'expected_supplier_refund_amount' => '400.00',
        'agency_fee_refund_amount' => '0.00',
        'settlement_notes' => 'Rollback-only cancellation settlement',
    ], $actorUserId, $accessibleBranchIds);

    $serviceWorkspace->refundService([
        'booking_id' => $scenario['ABC2']['booking_id'],
        'service_id' => $scenario['ABC2']['service_id'],
        'refund_reason' => 'Refund to Supplier Account',
        'refund_event_date' => $today,
        'refund_payment_method' => 'cash',
        'refund_treasury_account_id' => $riazTreasuryId,
        'supplier_refund_payment_method' => 'supplier_credit',
        'customer_refund_amount' => '0.00',
        'customer_refund_treatment' => 'keep_credit',
        'supplier_refund_amount' => '400.00',
        'refund_notes' => 'XYZ retained PKR 400 in supplier account for future use',
    ], $actorUserId, $accessibleBranchIds);

    $advanceStatement = $db->prepare(
        'SELECT id, deposit_amount, available_amount
         FROM supplier_advances
         WHERE supplier_id = :supplier_id AND currency = "PKR" AND deposit_amount = 400.00
         ORDER BY id DESC LIMIT 1'
    );
    $advanceStatement->execute(['supplier_id' => $supplierId]);
    $advance = $advanceStatement->fetch(PDO::FETCH_ASSOC) ?: [];
    $advanceId = (int) ($advance['id'] ?? 0);
    $availableAdvance = round((float) ($advance['available_amount'] ?? 0), 2);
    $globalPaymentAfterRefund = $supplierRepository->findSupplierPaymentById($globalPaymentId) ?? [];
    $unallocatedGlobalCredit = round((float) ($globalPaymentAfterRefund['unallocated_amount'] ?? 0), 2);
    $convertedGlobalCredit = round((float) ($globalPaymentAfterRefund['converted_advance_amount'] ?? 0), 2);
    $openAfterRetainedRefund = $openTotalFor($db, $supplierId, $unpaidReferences);
    $abc4AfterApply = $supplierRepository->findObligationById($scenario['ABC4']['obligation_id']);
    $abc5AfterApply = $supplierRepository->findObligationById($scenario['ABC5']['obligation_id']);
    $grossUnpaidInvoiceBasis = round(
        (float) ($abc4AfterApply['gross_amount'] ?? 0)
        + (float) ($abc5AfterApply['gross_amount'] ?? 0),
        2
    );
    $applicationStatement = $db->prepare(
        'SELECT COALESCE(SUM(applied_amount), 0)
         FROM supplier_advance_applications
         WHERE supplier_advance_id = :advance_id'
    );
    $applicationStatement->execute(['advance_id' => $advanceId]);
    $automaticallyApplied = round((float) ($applicationStatement->fetchColumn() ?: 0), 2);

    $ledgerBeforeApply = $supplierLedger->report([
        'dateFrom' => $today,
        'dateTo' => $today,
        'currency' => 'PKR',
        'airline' => '',
        'supplierId' => $supplierId,
        'businessSourceId' => 0,
        'bookingReference' => '',
    ], $accessibleBranchIds);
    $ledgerBalanceBeforeApply = $ledgerClosingBalance($ledgerBeforeApply);

    $check(
        'ABC2 cancellation converts PKR 400 returned to the supplier account exactly once',
        $unallocatedGlobalCredit === 0.00 && $convertedGlobalCredit === 400.00 && $advanceId > 0,
        'converted credit=PKR ' . number_format($convertedGlobalCredit, 2)
    );
    $check(
        'Refund to Supplier Account does not rewrite ABC4 and ABC5 original invoices',
        $grossUnpaidInvoiceBasis === 2000.00,
        'original gross payable basis=PKR ' . number_format($grossUnpaidInvoiceBasis, 2)
    );
    $check(
        'Global-payment refund credit becomes a formal advance and automatically pays the oldest open payable',
        $availableAdvance === 0.00
            && $automaticallyApplied === 400.00
            && round((float) ($abc4AfterApply['net_payable_amount'] ?? 0), 2) === 600.00
            && round((float) ($abc5AfterApply['net_payable_amount'] ?? 0), 2) === 1000.00,
        'applied=PKR ' . number_format($automaticallyApplied, 2)
            . '; ABC4=PKR ' . number_format((float) ($abc4AfterApply['net_payable_amount'] ?? 0), 2)
            . '; ABC5=PKR ' . number_format((float) ($abc5AfterApply['net_payable_amount'] ?? 0), 2)
    );

    echo PHP_EOL . 'Supplier ledger after refund is retained in the supplier account:' . PHP_EOL;
    echo str_pad('Customer', 10) . str_pad('Particulars', 54) . str_pad('Debit', 14, ' ', STR_PAD_LEFT) . str_pad('Credit', 14, ' ', STR_PAD_LEFT) . PHP_EOL;
    foreach ((array) ($ledgerBeforeApply['rows'] ?? []) as $row) {
        $bookingReference = (string) ($row['booking_reference'] ?? '');
        $customer = '';
        foreach ($scenario as $name => $data) {
            if ($data['booking_reference'] === $bookingReference) {
                $customer = $name;
                break;
            }
        }
        echo str_pad($customer, 10)
            . str_pad(substr((string) ($row['entry_type'] ?? ''), 0, 52), 54)
            . str_pad(number_format((float) ($row['raw_debit_amount'] ?? 0), 2), 14, ' ', STR_PAD_LEFT)
            . str_pad(number_format((float) ($row['raw_credit_amount'] ?? 0), 2), 14, ' ', STR_PAD_LEFT)
            . PHP_EOL;
    }
    echo 'Ledger closing payable: PKR ' . number_format($ledgerBalanceBeforeApply, 2) . PHP_EOL;
    echo 'Converted from global payment credit: PKR ' . number_format($convertedGlobalCredit, 2) . PHP_EOL;
    echo 'Automatically applied supplier advance: PKR ' . number_format($automaticallyApplied, 2) . PHP_EOL;
    echo 'Remaining reusable supplier advance: PKR ' . number_format($availableAdvance, 2) . PHP_EOL;
    echo 'Cash required now: PKR ' . number_format($openAfterRetainedRefund, 2) . PHP_EOL;

    $check(
        'Supplier payables and ledger both show the correct PKR 1,600 cash requirement',
        $openAfterRetainedRefund === 1600.00 && $ledgerBalanceBeforeApply === 1600.00,
        'open payables=PKR ' . number_format($openAfterRetainedRefund, 2)
            . '; ledger closing=PKR ' . number_format($ledgerBalanceBeforeApply, 2)
    );

    $accountDebit = 0.0;
    $accountCredit = 0.0;
    $accountRows = [];
    foreach ($scenario as $customer => $scenarioBooking) {
        $bookingAccountLedger = $accountLedger->report([
            'dateFrom' => $today,
            'dateTo' => $today,
            'currency' => 'PKR',
            'businessSourceId' => 0,
            'customerName' => '',
            'bookingReference' => $scenarioBooking['booking_reference'],
        ], [$branchId]);
        foreach ((array) ($bookingAccountLedger['rows'] ?? []) as $accountRow) {
            $accountRow['scenario_customer'] = $customer;
            $accountRows[] = $accountRow;
            $accountDebit += (float) ($accountRow['raw_debit_amount'] ?? 0);
            $accountCredit += (float) ($accountRow['raw_credit_amount'] ?? 0);
        }
    }
    $accountDebit = round($accountDebit, 2);
    $accountCredit = round($accountCredit, 2);
    $nonCashSupplierCreditRows = array_values(array_filter(
        $accountRows,
        static fn (array $row): bool => (string) ($row['ledger_entry'] ?? '') === 'Supplier credit applied'
    ));
    $retainedRefundAccountRows = array_values(array_filter(
        $accountRows,
        static fn (array $row): bool => str_contains(
            mb_strtolower((string) ($row['ledger_entry'] ?? '')),
            'supplier refund retained'
        )
    ));
    $check(
        'Account Ledger records only the PKR 3,000 cash paid from Riaz',
        $accountDebit === 0.00 && $accountCredit === 3000.00,
        'Debit/Money In=PKR ' . number_format($accountDebit, 2)
            . '; Credit/Money Out=PKR ' . number_format($accountCredit, 2)
    );
    $check(
        'Account Ledger shows retained/application credit as non-cash without duplicating treasury movement',
        count($nonCashSupplierCreditRows) === 1
            && count($retainedRefundAccountRows) === 1
            && abs((float) ($nonCashSupplierCreditRows[0]['raw_debit_amount'] ?? 0)) <= 0.005
            && abs((float) ($nonCashSupplierCreditRows[0]['raw_credit_amount'] ?? 0)) <= 0.005
            && abs((float) ($retainedRefundAccountRows[0]['raw_debit_amount'] ?? 0)) <= 0.005
            && abs((float) ($retainedRefundAccountRows[0]['raw_credit_amount'] ?? 0)) <= 0.005,
        'non-cash application rows=' . count($nonCashSupplierCreditRows)
            . '; retained refund rows=' . count($retainedRefundAccountRows)
    );

    $customerReportMethod = new ReflectionMethod($reportService, 'customerLedgerReport');
    $customerClosingBalances = [];
    foreach ($scenario as $customer => $scenarioBooking) {
        $rawCustomerRows = $reportRepository->customerLedger(
            [$branchId],
            $today,
            $today,
            'PKR',
            0,
            '',
            $scenarioBooking['booking_reference']
        );
        [$customerRows] = $customerReportMethod->invoke(
            $reportService,
            $rawCustomerRows,
            ['PKR' => 1.0],
            'customer',
            true
        );
        $customerClosingBalances[$customer] = (string) (
            ($customerRows[array_key_last($customerRows)] ?? [])['balance_amount'] ?? ''
        );
    }
    $check(
        'Customer Ledgers preserve open invoices and reduce cancelled ABC2 to the PKR 600 supplier penalty',
        ($customerClosingBalances['ABC1'] ?? '') === '1,000.00 Cr'
            && ($customerClosingBalances['ABC2'] ?? '') === '600.00 Cr'
            && ($customerClosingBalances['ABC3'] ?? '') === '1,000.00 Cr'
            && ($customerClosingBalances['ABC4'] ?? '') === '1,000.00 Cr'
            && ($customerClosingBalances['ABC5'] ?? '') === '1,000.00 Cr',
        json_encode($customerClosingBalances, JSON_UNESCAPED_SLASHES)
    );

    $supplierAdvanceApplicationRows = array_values(array_filter(
        (array) ($ledgerBeforeApply['rows'] ?? []),
        static fn (array $row): bool => (string) ($row['entry_type'] ?? '') === 'Supplier advance applied'
            && abs((float) ($row['raw_credit_amount'] ?? 0) - 400.00) <= 0.005
    ));
    $duplicateSupplierAdvancePaidRows = array_values(array_filter(
        (array) ($ledgerBeforeApply['rows'] ?? []),
        static fn (array $row): bool => (string) ($row['entry_type'] ?? '') === 'Supplier advance paid'
    ));
    $check(
        'Supplier Ledger shows one PKR 400 application and no duplicate advance payment',
        count($supplierAdvanceApplicationRows) === 1 && $duplicateSupplierAdvancePaidRows === [],
        'application rows=' . count($supplierAdvanceApplicationRows)
            . '; duplicate advance-payment rows=' . count($duplicateSupplierAdvancePaidRows)
    );

    $scenarioPaymentStatement = $db->prepare(
        'SELECT COUNT(*) AS payment_count,
                COALESCE(SUM(paid_amount), 0) AS paid_amount,
                COALESCE(SUM(allocated_amount), 0) AS allocated_amount,
                COALESCE(SUM(unallocated_amount), 0) AS unallocated_amount
         FROM supplier_payments
         WHERE id = :payment_id
           AND treasury_account_id = :treasury_account_id
           AND status <> "void"'
    );
    $scenarioPaymentStatement->execute([
        'payment_id' => $globalPaymentId,
        'treasury_account_id' => $riazTreasuryId,
    ]);
    $scenarioPaymentTruth = $scenarioPaymentStatement->fetch(PDO::FETCH_ASSOC) ?: [];
    $check(
        'Treasury/payment truth remains one PKR 3,000 payment with no unallocated duplicate',
        (int) ($scenarioPaymentTruth['payment_count'] ?? 0) === 1
            && round((float) ($scenarioPaymentTruth['paid_amount'] ?? 0), 2) === 3000.00
            && round((float) ($scenarioPaymentTruth['allocated_amount'] ?? 0), 2) === 2600.00
            && round((float) ($scenarioPaymentTruth['unallocated_amount'] ?? 0), 2) === 0.00,
        json_encode($scenarioPaymentTruth, JSON_UNESCAPED_SLASHES)
    );

    $imbalances = (int) $db->query(
        'SELECT COUNT(*) FROM (
            SELECT journal_entry_id
            FROM journal_entry_lines
            GROUP BY journal_entry_id
            HAVING ABS(SUM(debit_amount) - SUM(credit_amount)) > 0.005
         ) broken'
    )->fetchColumn();
    $check('Every journal remains balanced throughout the scenario', $imbalances === 0);
} catch (Throwable $exception) {
    $check('Scenario completed without exception', false, $exception->getMessage());
} finally {
    if ($db->inTransaction()) {
        if ($keepData && $failures === []) {
            $db->commit();
        } else {
            $db->rollBack();
        }
    }
}

$leftoverStatement = $db->prepare('SELECT COUNT(*) FROM suppliers WHERE name = :name');
$leftoverStatement->execute(['name' => $supplierName]);
$leftoverCount = (int) $leftoverStatement->fetchColumn();
if ($keepData && $failures === []) {
    $check('Successful scenario data was committed for manual verification', $leftoverCount === 1);
    echo PHP_EOL . 'Persistent scenario references:' . PHP_EOL;
    echo 'Supplier: ' . $supplierName . PHP_EOL;
    echo 'Treasury account: Riaz / PKR (ID ' . $riazTreasuryId . ')' . PHP_EOL;
    echo 'Global supplier payment ID: ' . $globalPaymentId . PHP_EOL;
    foreach ($scenario as $customer => $scenarioBooking) {
        echo $customer . ': ' . $scenarioBooking['booking_reference']
            . ' (booking ID ' . $scenarioBooking['booking_id'] . ')' . PHP_EOL;
    }
} else {
    $check('Rollback removed all temporary XYZ scenario data', $leftoverCount === 0);
}

if ($failures !== []) {
    echo PHP_EOL . 'Five-ticket supplier cancellation/credit scenario failed:' . PHP_EOL;
    foreach ($failures as $failure) {
        echo ' - ' . $failure . PHP_EOL;
    }
    exit(1);
}

echo PHP_EOL . 'RESULT: PASS — gross unpaid invoices remain PKR 2,000, supplier credit is PKR 400, and economic net cash required is PKR 1,600.' . PHP_EOL;
exit(0);
