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
$failures = [];
$check = static function (string $label, bool $passed) use (&$failures): void {
    echo ($passed ? '[PASS] ' : '[FAIL] ') . $label . PHP_EOL;
    if (! $passed) {
        $failures[] = $label;
    }
};

$bookingStatement = $db->prepare(
    'SELECT id, booking_reference
     FROM bookings
     WHERE booking_reference IN ("BK-000270", "BK-000277")'
);
$bookingStatement->execute();
$bookingIds = [];
foreach ($bookingStatement->fetchAll() ?: [] as $row) {
    $bookingIds[(string) $row['booking_reference']] = (int) $row['id'];
}

$receiptStatement = $db->prepare(
    'SELECT id, unallocated_amount
     FROM customer_receipts
     WHERE booking_reference = "BK-000270"
       AND currency = "AED"
       AND status <> "void"
     ORDER BY id ASC
     LIMIT 1'
);
$receiptStatement->execute();
$creditReceipt = $receiptStatement->fetch() ?: [];
$creditReceiptId = (int) ($creditReceipt['id'] ?? 0);

if (($bookingIds['BK-000270'] ?? 0) <= 0 || ($bookingIds['BK-000277'] ?? 0) <= 0 || $creditReceiptId <= 0) {
    echo '[SKIP] BK-000270/BK-000277 AED 844 fixture is not present in this database.' . PHP_EOL;
    exit(0);
}

$creditRepository = new \App\Repositories\CustomerPaymentRepository($app);
$draftCredits = $creditRepository->availableBookingCredits(2, 178, 'AED');
$draftCreditIds = array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $draftCredits);
$creditAlreadyApplied = abs((float) ($creditReceipt['unallocated_amount'] ?? 0)) <= 0.005;
$check(
    $creditAlreadyApplied
        ? 'Consumed customer credit is no longer offered to another unsaved invoice'
        : 'Available credit is discoverable while the new invoice is still an unsaved draft',
    $creditAlreadyApplied
        ? ! in_array($creditReceiptId, $draftCreditIds, true)
        : in_array($creditReceiptId, $draftCreditIds, true)
);

$db->beginTransaction();
try {
    if (! $creditAlreadyApplied) {
        $service = new \App\Services\CustomerReceiptWorkspaceService($app);
        $result = $service->saveReceipt([
            'booking_id' => $bookingIds['BK-000277'],
            'receipt_action' => 'no_receipt',
            'receipt_scope' => 'whole_invoice',
            'receipt_currency' => 'AED',
            'received_amount' => '0',
            'receipt_date' => '2026-07-20',
            'payment_method' => 'cash',
            'charges_amount' => '0',
            'receipt_status' => 'received',
            'customer_credit_receipt_id' => $creditReceiptId,
            'customer_credit_apply_amount' => '844',
            'advance_receipt_id' => '',
            'advance_apply_amount' => '0',
        ], 1, [2]);
        $creditResult = (array) ($result['customer_credit_applied'] ?? []);
    } else {
        $existingAllocationStatement = $db->prepare(
            'SELECT cra.id, cra.allocated_amount
             FROM customer_receipt_allocations cra
             INNER JOIN customer_receivable_items cri ON cri.id = cra.customer_receivable_item_id
             WHERE cra.customer_receipt_id = :receipt_id
               AND cri.booking_reference = "BK-000277"
               AND cri.currency = "AED"
             ORDER BY cra.id DESC
             LIMIT 1'
        );
        $existingAllocationStatement->execute(['receipt_id' => $creditReceiptId]);
        $existingAllocation = $existingAllocationStatement->fetch() ?: [];
        $creditResult = [
            'allocated_amount' => (float) ($existingAllocation['allocated_amount'] ?? 0),
            'allocation_ids' => [(int) ($existingAllocation['id'] ?? 0)],
        ];
        $result = ['receipt' => []];
    }
    $check(
        'AED 844 customer credit is transferred without creating a new receipt',
        abs((float) ($creditResult['allocated_amount'] ?? 0) - 844.00) <= 0.005
            && (int) ($result['receipt']['id'] ?? 0) === 0
    );

    $stateStatement = $db->prepare(
        'SELECT
            (SELECT unallocated_amount FROM customer_receipts WHERE id = :receipt_id) AS source_credit,
            (SELECT outstanding_amount FROM customer_receivable_items
             WHERE booking_reference = "BK-000277" AND currency = "AED" ORDER BY id ASC LIMIT 1) AS target_outstanding'
    );
    $stateStatement->execute(['receipt_id' => $creditReceiptId]);
    $state = $stateStatement->fetch() ?: [];
    $check(
        'Source credit closes and BK-000277 retains AED 606 outstanding',
        abs((float) ($state['source_credit'] ?? -1) - 0.00) <= 0.005
            && abs((float) ($state['target_outstanding'] ?? -1) - 606.00) <= 0.005
    );

    $allocationId = (int) (($creditResult['allocation_ids'][0] ?? 0));
    $journalStatement = $db->prepare(
        'SELECT
            je.booking_reference,
            je.source_type,
            ROUND(SUM(jl.debit_amount), 2) AS debit_total,
            ROUND(SUM(jl.credit_amount), 2) AS credit_total
         FROM journal_entries je
         INNER JOIN journal_entry_lines jl ON jl.journal_entry_id = je.id
         WHERE je.source_reference = :source_reference
         GROUP BY je.id, je.booking_reference, je.source_type'
    );
    $journalStatement->execute(['source_reference' => 'RCPT-000264-XFER-' . $allocationId]);
    $journal = $journalStatement->fetch() ?: [];
    $check(
        'Transfer posts one balanced Customer Credit to AR journal under BK-000277',
        (string) ($journal['booking_reference'] ?? '') === 'BK-000277'
            && (string) ($journal['source_type'] ?? '') === 'customer_receipt_allocated'
            && abs((float) ($journal['debit_total'] ?? 0) - 844.00) <= 0.005
            && abs((float) ($journal['credit_total'] ?? 0) - 844.00) <= 0.005
    );

    $filters = [
        'dateFrom' => '',
        'dateTo' => '',
        'currency' => 'AED',
        'businessSourceId' => 0,
        'customerName' => '',
        'bookingReference' => 'BK-000270',
    ];
    $accountService = new \App\Services\AccountLedgerService($app);
    $sourceLedger = $accountService->report($filters, [2]);
    $sourceTransfer = array_values(array_filter(
        (array) ($sourceLedger['rows'] ?? []),
        static fn (array $row): bool => str_starts_with((string) ($row['ledger_entry'] ?? ''), 'Customer credit applied to')
    ))[0] ?? [];
    $check(
        'BK-000270 Account Ledger shows AED 844 Credit transferred to BK-000277',
        abs((float) ($sourceTransfer['raw_credit_amount'] ?? 0) - 844.00) <= 0.005
            && str_contains((string) ($sourceTransfer['ledger_entry'] ?? ''), 'BK-000277')
            && abs((float) ($sourceTransfer['raw_balance_amount'] ?? 0) - 55.00) <= 0.005
    );

    $filters['bookingReference'] = 'BK-000277';
    $targetLedger = $accountService->report($filters, [2]);
    $targetTransfer = array_values(array_filter(
        (array) ($targetLedger['rows'] ?? []),
        static fn (array $row): bool => str_starts_with((string) ($row['ledger_entry'] ?? ''), 'Customer credit received from')
    ))[0] ?? [];
    $check(
        'BK-000277 Account Ledger shows AED 844 Debit received from BK-000270',
        abs((float) ($targetTransfer['raw_debit_amount'] ?? 0) - 844.00) <= 0.005
            && str_contains((string) ($targetTransfer['ledger_entry'] ?? ''), 'BK-000270')
    );

    $reportRepository = new \App\Repositories\ReportRepository($app);
    $reportService = new \App\Services\ReportService($app);
    $customerRowMethod = new ReflectionMethod($reportService, 'customerLedgerRowsForCustomer');
    $sourceRawRows = $reportRepository->customerLedger([2], null, null, 'AED', 0, '', 'BK-000270');
    $targetRawRows = $reportRepository->customerLedger([2], null, null, 'AED', 0, '', 'BK-000277');
    $sourceTransferRaw = array_values(array_filter(
        $sourceRawRows,
        static fn (array $row): bool => (string) ($row['row_type'] ?? '') === 'customer_credit_transfer'
    ))[0] ?? [];
    $targetTransferRaw = array_values(array_filter(
        $targetRawRows,
        static fn (array $row): bool => (string) ($row['row_type'] ?? '') === 'customer_credit_transfer'
    ))[0] ?? [];
    $sourceCustomerRows = $sourceTransferRaw !== [] ? $customerRowMethod->invoke($reportService, $sourceTransferRaw, []) : [];
    $targetCustomerRows = $targetTransferRaw !== [] ? $customerRowMethod->invoke($reportService, $targetTransferRaw, []) : [];
    $check(
        'Customer Ledger shows the refund-credit use on BK-000270 and receipt on BK-000277',
        abs((float) ($sourceCustomerRows[0]['raw_credit_amount'] ?? 0) - 844.00) <= 0.005
            && abs((float) ($targetCustomerRows[0]['raw_debit_amount'] ?? 0) - 844.00) <= 0.005
            && str_contains((string) ($sourceCustomerRows[0]['ledger_entry'] ?? ''), 'BK-000277')
            && str_contains((string) ($targetCustomerRows[0]['ledger_entry'] ?? ''), 'BK-000270')
    );

    $customerReportMethod = new ReflectionMethod($reportService, 'customerLedgerReport');
    [$sourceCustomerLedger] = $customerReportMethod->invoke($reportService, $sourceRawRows, ['AED' => 1.0], 'customer', true);
    [$targetCustomerLedger, $targetCustomerSummaryCards] = $customerReportMethod->invoke(
        $reportService,
        $targetRawRows,
        ['AED' => 1.0],
        'customer',
        true
    );
    $sourceClosingBalance = (string) (($sourceCustomerLedger[array_key_last($sourceCustomerLedger)] ?? [])['balance_amount'] ?? '');
    $targetClosingBalance = (string) (($targetCustomerLedger[array_key_last($targetCustomerLedger)] ?? [])['balance_amount'] ?? '');
    if ($sourceClosingBalance !== '-' || $targetClosingBalance !== '606.00 Cr') {
        echo '[INFO] Customer Ledger closing balances: BK-000270=' . $sourceClosingBalance
            . ', BK-000277=' . $targetClosingBalance . PHP_EOL;
        echo '[INFO] BK-000277 Customer Ledger rows: ' . json_encode(array_map(
            static fn (array $row): array => [
                'entry' => (string) ($row['description'] ?? ''),
                'debit' => (string) ($row['debit_amount'] ?? ''),
                'credit' => (string) ($row['credit_amount'] ?? ''),
                'balance' => (string) ($row['balance_amount'] ?? ''),
            ],
            $targetCustomerLedger
        ), JSON_UNESCAPED_SLASHES) . PHP_EOL;
    }
    $check(
        'Booking-filtered Customer Ledgers close BK-000270 and leave BK-000277 at AED 606 Cr',
        $sourceClosingBalance === '-'
            && $targetClosingBalance === '606.00 Cr'
    );

    $targetCustomerSummary = array_column($targetCustomerSummaryCards, 'value', 'label');
    $check(
        'BK-000277 Customer Ledger summary includes AED 844 applied customer credit',
        (string) ($targetCustomerSummary['Debit / AED'] ?? '') === 'AED 844.00'
            && (string) ($targetCustomerSummary['Credit / AED'] ?? '') === 'AED 1,450.00'
            && (string) ($targetCustomerSummary['Balance / AED'] ?? '') === 'AED -606.00'
    );

    $sourceSummary = (array) (($sourceLedger['summaryRows'][0] ?? []));
    $check(
        'BK-000270 Account Ledger summary retains original non-refund totals',
        (string) ($sourceSummary['total_debit'] ?? '') === '1,070.00'
            && (string) ($sourceSummary['total_credit'] ?? '') === '1,015.00'
    );
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
}

if ($failures !== []) {
    fwrite(STDERR, "Customer booking credit transfer regression failed:\n - " . implode("\n - ", $failures) . PHP_EOL);
    exit(1);
}

echo 'Customer booking credit transfer regression passed.' . PHP_EOL;
