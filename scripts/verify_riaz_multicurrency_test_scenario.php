<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';

$app = \App\Core\App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');

$marker = 'RIAZ-MULTI-CURRENCY-DEMO-20260723';
$failures = [];
$check = static function (bool $condition, string $label, string $detail = '') use (&$failures): void {
    echo ($condition ? '[PASS] ' : '[FAIL] ') . $label . ($detail !== '' ? ' - ' . $detail : '') . PHP_EOL;
    if (! $condition) {
        $failures[] = $label;
    }
};

echo 'Riaz retained multi-currency scenario verification (read-only)' . PHP_EOL;
echo 'Marker: ' . $marker . PHP_EOL;
echo 'This script performs SELECT/report queries only; it cannot change data.' . PHP_EOL . PHP_EOL;

$scenarioStatement = $db->prepare(
    'SELECT b.id booking_id,
            b.booking_reference,
            b.branch_id,
            b.business_source_id,
            COALESCE(NULLIF(bp.lead_traveler_name, ""), NULLIF(t.full_name, ""), "") customer_name,
            bs.id service_id,
            bs.line_reference,
            bs.currency invoice_currency,
            bs.cost_currency,
            bs.service_charge_currency,
            bs.final_sale_price,
            bs.purchase_cost,
            bs.net_profit_loss,
            bs.service_status,
            cri.id receivable_id,
            cri.due_amount,
            cri.allocated_amount customer_allocated,
            cri.outstanding_amount customer_outstanding,
            so.id obligation_id,
            so.supplier_id,
            so.gross_amount,
            so.net_payable_amount supplier_outstanding
     FROM bookings b
     INNER JOIN booking_services bs ON bs.booking_id = b.id
     INNER JOIN customer_receivable_items cri
        ON cri.booking_reference = b.booking_reference
       AND cri.service_line_reference = bs.line_reference
     INNER JOIN supplier_obligations so
        ON so.booking_reference = b.booking_reference
       AND so.service_line_reference = bs.line_reference
     LEFT JOIN booking_parties bp ON bp.booking_id = b.id
     LEFT JOIN travelers t ON t.id = b.lead_traveler_id
     WHERE b.remarks LIKE :marker
     ORDER BY b.id'
);
$scenarioStatement->execute(['marker' => '%' . $marker . '%']);
$scenarioRows = $scenarioStatement->fetchAll(PDO::FETCH_ASSOC) ?: [];
$scenario = [];
foreach ($scenarioRows as $row) {
    $scenario[(string) ($row['customer_name'] ?? '')] = $row;
}

$check(
    count($scenarioRows) === 5 && array_keys($scenario) === ['ABC1', 'ABC2', 'ABC3', 'ABC4', 'ABC5'],
    'Exactly five retained ABC1-ABC5 bookings exist',
    implode(', ', array_map(static fn (array $row): string => (string) $row['booking_reference'], $scenarioRows))
);

$sourceStatement = $db->prepare('SELECT id, name FROM business_sources WHERE LOWER(name) = "riaz" LIMIT 1');
$sourceStatement->execute();
$businessSource = $sourceStatement->fetch(PDO::FETCH_ASSOC) ?: [];
$businessSourceId = (int) ($businessSource['id'] ?? 0);
$supplierStatement = $db->prepare('SELECT id, name FROM suppliers WHERE LOWER(name) = "xyz" LIMIT 1');
$supplierStatement->execute();
$supplier = $supplierStatement->fetch(PDO::FETCH_ASSOC) ?: [];
$supplierId = (int) ($supplier['id'] ?? 0);
$check($businessSourceId > 0, 'Account holder Riaz exists', 'ID ' . $businessSourceId);
$check($supplierId > 0, 'Supplier XYZ exists', 'ID ' . $supplierId);

$expectedCurrencies = [
    'ABC1' => ['PKR', 'PKR', 'AED'],
    'ABC2' => ['PKR', 'PKR', 'PKR'],
    'ABC3' => ['AED', 'AED', 'AED'],
    'ABC4' => ['USD', 'PKR', 'PKR'],
    'ABC5' => ['AED', 'USD', 'USD'],
];
$currencyTruth = [];
foreach ($scenario as $customer => $row) {
    $receiptStatement = $db->prepare(
        'SELECT id, receipt_no, currency, received_amount, allocated_amount, unallocated_amount, returned_amount, status
         FROM customer_receipts
         WHERE booking_reference = :booking_reference AND status <> "void"
         ORDER BY id DESC LIMIT 1'
    );
    $receiptStatement->execute(['booking_reference' => $row['booking_reference']]);
    $receipt = $receiptStatement->fetch(PDO::FETCH_ASSOC) ?: [];
    $scenario[$customer]['receipt'] = $receipt;
    $currencyTruth[$customer] = [
        'invoice' => (string) $row['invoice_currency'],
        'service' => (string) $row['service_charge_currency'],
        'payment' => (string) ($receipt['currency'] ?? ''),
    ];
}

$currencyMatches = true;
foreach ($expectedCurrencies as $customer => [$invoiceCurrency, $serviceCurrency, $paymentCurrency]) {
    $actual = $currencyTruth[$customer] ?? [];
    $currencyMatches = $currencyMatches
        && ($actual['invoice'] ?? '') === $invoiceCurrency
        && ($actual['service'] ?? '') === $serviceCurrency
        && ($actual['payment'] ?? '') === $paymentCurrency;
}
$check(
    $currencyMatches,
    'Invoice, service-fee, and receipt currencies cover five mixed-currency combinations',
    json_encode($currencyTruth, JSON_UNESCAPED_SLASHES)
);

$positionTruth = [];
$positionsCorrect = true;
foreach ($scenario as $customer => $row) {
    $expectedDue = $customer === 'ABC2' ? 400.00 : 1000.00;
    $expectedSupplierOutstanding = in_array($customer, ['ABC4', 'ABC5'], true) ? 1000.00 : 0.00;
    $positionsCorrect = $positionsCorrect
        && round((float) $row['due_amount'], 2) === $expectedDue
        && round((float) $row['customer_outstanding'], 2) === 0.00
        && round((float) $row['gross_amount'], 2) === $expectedDue
        && round((float) $row['supplier_outstanding'], 2) === $expectedSupplierOutstanding;
    $positionTruth[$customer] = [
        'receivable_due' => round((float) $row['due_amount'], 2),
        'receivable_open' => round((float) $row['customer_outstanding'], 2),
        'supplier_gross' => round((float) $row['gross_amount'], 2),
        'supplier_open' => round((float) $row['supplier_outstanding'], 2),
    ];
}
$check(
    $positionsCorrect,
    'Customer and supplier positions reflect the ABC2 cancellation without affecting ABC4/ABC5',
    json_encode($positionTruth, JSON_UNESCAPED_SLASHES)
);

$receiptTruth = [];
$receiptsConserve = true;
foreach ($scenario as $customer => $row) {
    $receipt = (array) ($row['receipt'] ?? []);
    $received = round((float) ($receipt['received_amount'] ?? 0), 2);
    $allocated = round((float) ($receipt['allocated_amount'] ?? 0), 2);
    $unallocated = round((float) ($receipt['unallocated_amount'] ?? 0), 2);
    $returned = round((float) ($receipt['returned_amount'] ?? 0), 2);
    $receiptsConserve = $receiptsConserve
        && abs($received - $allocated - $unallocated - $returned) <= 0.005
        && $unallocated === 0.00;
    $receiptTruth[$customer] = [
        'receipt' => (string) ($receipt['receipt_no'] ?? ''),
        'currency' => (string) ($receipt['currency'] ?? ''),
        'received' => $received,
        'allocated' => $allocated,
        'returned' => $returned,
        'unallocated' => $unallocated,
    ];
}
$check(
    $receiptsConserve,
    'All five customer receipts conserve value and leave no unallocated credit',
    json_encode($receiptTruth, JSON_UNESCAPED_SLASHES)
);

$cancelStatement = $db->prepare(
    'SELECT
        JSON_UNQUOTE(JSON_EXTRACT(c.payload_json, "$.supplier_penalty_amount")) supplier_penalty,
        JSON_UNQUOTE(JSON_EXTRACT(c.payload_json, "$.expected_supplier_refund_amount")) expected_supplier_refund,
        COALESCE(SUM(CASE WHEN r.event_type = "refund" AND r.event_status = "posted" THEN r.customer_refund_amount ELSE 0 END), 0) customer_refund,
        COALESCE(SUM(CASE WHEN r.event_type = "refund" AND r.event_status = "posted" THEN r.supplier_refund_amount ELSE 0 END), 0) supplier_refund
     FROM booking_service_events c
     LEFT JOIN booking_service_events r ON r.booking_service_id = c.booking_service_id AND r.id <> c.id
     WHERE c.booking_service_id = :service_id AND c.event_type = "cancel" AND c.event_status = "posted"
     GROUP BY c.id
     ORDER BY c.id DESC LIMIT 1'
);
$cancelStatement->execute(['service_id' => (int) ($scenario['ABC2']['service_id'] ?? 0)]);
$cancelTruth = $cancelStatement->fetch(PDO::FETCH_ASSOC) ?: [];
$check(
    round((float) ($cancelTruth['supplier_penalty'] ?? 0), 2) === 400.00
        && round((float) ($cancelTruth['expected_supplier_refund'] ?? 0), 2) === 600.00
        && round((float) ($cancelTruth['customer_refund'] ?? 0), 2) === 600.00
        && round((float) ($cancelTruth['supplier_refund'] ?? 0), 2) === 600.00,
    'ABC2 contains PKR 400 supplier penalty, PKR 600 supplier refund, and PKR 600 customer refund',
    json_encode($cancelTruth, JSON_UNESCAPED_SLASHES)
);

$paymentStatement = $db->prepare(
    'SELECT sp.id, sp.payment_no, sp.paid_amount, sp.allocated_amount, sp.unallocated_amount, sp.returned_amount, sp.currency, sp.status,
            COUNT(spa.id) allocation_rows,
            COALESCE(SUM(spa.allocated_amount), 0) allocation_total
     FROM supplier_payments sp
     LEFT JOIN supplier_payment_allocations spa ON spa.supplier_payment_id = sp.id
     WHERE sp.supplier_id = :supplier_id AND sp.remarks LIKE :marker AND sp.status <> "void"
     GROUP BY sp.id
     ORDER BY sp.id DESC LIMIT 1'
);
$paymentStatement->execute(['supplier_id' => $supplierId, 'marker' => '%' . $marker . '%']);
$supplierPayment = $paymentStatement->fetch(PDO::FETCH_ASSOC) ?: [];
$check(
    round((float) ($supplierPayment['paid_amount'] ?? 0), 2) === 3000.00
        && (string) ($supplierPayment['currency'] ?? '') === 'PKR'
        && (int) ($supplierPayment['allocation_rows'] ?? 0) >= 2
        && round((float) ($supplierPayment['allocation_total'] ?? 0), 2) === 2400.00
        && round((float) ($supplierPayment['allocated_amount'] ?? 0), 2) === 2400.00
        && round((float) ($supplierPayment['unallocated_amount'] ?? 0), 2) === 0.00
        && round((float) ($supplierPayment['returned_amount'] ?? 0), 2) === 600.00,
    'XYZ payment records PKR 2,400 allocated plus PKR 600 returned, with no false reusable advance',
    json_encode($supplierPayment, JSON_UNESCAPED_SLASHES)
);

$accountLedger = new \App\Services\AccountLedgerService($app);
$accountRows = [];
$accountReferencesPresent = true;
foreach ($scenario as $row) {
    $report = $accountLedger->report([
        'dateFrom' => null,
        'dateTo' => null,
        'currency' => '',
        'businessSourceId' => $businessSourceId,
        'customerName' => '',
        'bookingReference' => (string) $row['booking_reference'],
    ], [(int) $row['branch_id']]);
    $rows = (array) ($report['rows'] ?? []);
    $accountReferencesPresent = $accountReferencesPresent && $rows !== [];
    $accountRows[(string) $row['booking_reference']] = array_map(
        static fn (array $ledgerRow): array => [
            'entry' => (string) ($ledgerRow['ledger_entry'] ?? ''),
            'currency' => (string) ($ledgerRow['currency'] ?? ''),
            'debit' => round((float) ($ledgerRow['raw_debit_amount'] ?? 0), 2),
            'credit' => round((float) ($ledgerRow['raw_credit_amount'] ?? 0), 2),
        ],
        $rows
    );
}
$check(
    $accountReferencesPresent,
    'Account Ledger exposes actual money movements for every retained booking',
    json_encode($accountRows, JSON_UNESCAPED_SLASHES)
);

$accountReport = $accountLedger->report([
    'dateFrom' => null,
    'dateTo' => null,
    'currency' => '',
    'businessSourceId' => $businessSourceId,
    'customerName' => '',
    'bookingReference' => '',
], [1, 2]);
$profitCards = array_column((array) ($accountReport['summaryCards'] ?? []), 'value', 'label');
$check(
    (string) ($profitCards['Total Profit / AED'] ?? '') === '1,974.00 Profit'
        && (string) ($profitCards['Total Profit / PKR'] ?? '') === '0.00'
        && (string) ($profitCards['Total Profit / USD'] ?? '') === '997.00 Profit',
    'Riaz Account Ledger totals profit independently in AED, PKR, and USD',
    json_encode($profitCards, JSON_UNESCAPED_SLASHES)
);

$reportRepository = new \App\Repositories\ReportRepository($app);
$reportService = new \App\Services\ReportService($app);
$customerReportMethod = new ReflectionMethod($reportService, 'customerLedgerReport');
$customerClosing = [];
$customerLedgerDetails = [];
$customerLedgersClose = true;
foreach ($scenario as $customer => $row) {
    $currency = (string) $row['invoice_currency'];
    $rawRows = $reportRepository->customerLedger(
        [(int) $row['branch_id']],
        null,
        null,
        $currency,
        $businessSourceId,
        '',
        (string) $row['booking_reference']
    );
    [$ledgerRows] = $customerReportMethod->invoke(
        $reportService,
        $rawRows,
        ['PKR' => 1.0, 'AED' => 80.0, 'USD' => 320.0],
        'customer',
        true
    );
    $closing = (string) (($ledgerRows[array_key_last($ledgerRows)] ?? [])['balance_amount'] ?? '');
    $customerClosing[$customer] = $closing;
    $customerLedgerDetails[$customer] = array_map(
        static fn (array $ledgerRow): array => [
            'entry' => (string) ($ledgerRow['ledger_entry'] ?? $ledgerRow['description'] ?? ''),
            'currency' => (string) ($ledgerRow['currency'] ?? ''),
            'debit' => round((float) ($ledgerRow['raw_debit_amount'] ?? 0), 2),
            'credit' => round((float) ($ledgerRow['raw_credit_amount'] ?? 0), 2),
            'balance' => (string) ($ledgerRow['balance_amount'] ?? ''),
        ],
        $ledgerRows
    );
    $customerLedgersClose = $customerLedgersClose && $ledgerRows !== [] && $closing === '-';
}
$check(
    $customerLedgersClose,
    'ABC1-ABC5 Customer Ledgers all close at zero after receipts/refund',
    json_encode([
        'closing' => $customerClosing,
        'rows' => $customerLedgerDetails,
    ], JSON_UNESCAPED_SLASHES)
);

$supplierLedger = new \App\Services\SupplierLedgerService($app);
$supplierReport = $supplierLedger->report([
    'dateFrom' => null,
    'dateTo' => null,
    'currency' => 'PKR',
    'airline' => '',
    'supplierId' => $supplierId,
    'businessSourceId' => $businessSourceId,
    'bookingReference' => '',
], [1, 2]);
$supplierRows = (array) ($supplierReport['rows'] ?? []);
$supplierClosing = $supplierRows === []
    ? 0.0
    : round((float) ($supplierRows[array_key_last($supplierRows)]['raw_balance_amount'] ?? 0), 2);
$check(
    $supplierClosing === 2000.00,
    'XYZ Supplier Ledger closes with exactly PKR 2,000 still payable',
    'PKR ' . number_format($supplierClosing, 2)
);

$journalImbalances = (int) $db->query(
    'SELECT COUNT(*) FROM (
        SELECT journal_entry_id
        FROM journal_entry_lines
        GROUP BY journal_entry_id
        HAVING ABS(SUM(debit_amount) - SUM(credit_amount)) > 0.005
     ) broken'
)->fetchColumn();
$check($journalImbalances === 0, 'Every journal entry in the database remains balanced');

echo PHP_EOL . 'Retained references for manual inspection:' . PHP_EOL;
foreach ($scenario as $customer => $row) {
    echo sprintf(
        ' - %s: %s | invoice %s | service fee %s | receipt %s',
        $customer,
        (string) $row['booking_reference'],
        (string) $row['invoice_currency'],
        (string) $row['service_charge_currency'],
        (string) (($row['receipt'] ?? [])['receipt_no'] ?? '')
    ) . PHP_EOL;
}

if ($failures !== []) {
    fwrite(STDERR, PHP_EOL . 'RESULT: FAIL' . PHP_EOL . ' - ' . implode(PHP_EOL . ' - ', $failures) . PHP_EOL);
    exit(1);
}

echo PHP_EOL . 'RESULT: PASS - retained scenario, ledgers, allocations, refunds, profit, and journals are consistent.' . PHP_EOL;
