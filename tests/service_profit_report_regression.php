<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';

$app = (isset($app) && $app instanceof \App\Core\App)
    ? $app
    : \App\Core\App::bootstrap(BASE_PATH);

$repository = new \App\Repositories\ReportRepository($app);
$rows = $repository->serviceProfit([1, 2], null, null);
$failures = [];

$check = static function (bool $passed, string $label, mixed $detail = null) use (&$failures): void {
    echo ($passed ? '[PASS] ' : '[FAIL] ') . $label;
    if ($detail !== null) {
        echo ' - ' . json_encode($detail, JSON_UNESCAPED_SLASHES);
    }
    echo PHP_EOL;
    if (! $passed) {
        $failures[] = $label;
    }
};

echo 'Mixed-currency service-profit report regression' . PHP_EOL;
echo 'Started: ' . date(DATE_ATOM) . PHP_EOL . PHP_EOL;

$mixedRows = array_values(array_filter(
    $rows,
    static fn (array $row): bool => strtoupper((string) ($row['currency'] ?? ''))
        !== strtoupper((string) ($row['payable_currency'] ?? $row['currency'] ?? ''))
));

$invalidMixedRows = [];
foreach ($mixedRows as $row) {
    $rawPayable = (float) ($row['payable_original_amount'] ?? 0);
    $convertedPayable = (float) ($row['payable_amount'] ?? 0);
    $rate = (float) ($row['pricing_exchange_rate'] ?? 0);
    $expected = round($rawPayable * $rate, 2);
    if ($rate <= 0 || abs($convertedPayable - $expected) > 0.01) {
        $invalidMixedRows[] = [
            'booking_reference' => (string) ($row['booking_reference'] ?? ''),
            'invoice_currency' => (string) ($row['currency'] ?? ''),
            'payable_currency' => (string) ($row['payable_currency'] ?? ''),
            'raw_payable' => $rawPayable,
            'pricing_exchange_rate' => $rate,
            'expected_invoice_payable' => $expected,
            'reported_invoice_payable' => $convertedPayable,
        ];
    }
}

$check(
    $invalidMixedRows === [],
    'Every mixed-currency supplier cost is converted before profit subtraction',
    $invalidMixedRows !== [] ? array_slice($invalidMixedRows, 0, 10) : ['mixed_rows' => count($mixedRows)]
);

$supplierRow = null;
foreach ($rows as $row) {
    if ((int) ($row['supplier_id'] ?? 0) > 0) {
        $supplierRow = $row;
        break;
    }
}
if ($supplierRow === null) {
    echo '[SKIP] No supplier-linked service exists for the supplier-filter regression.' . PHP_EOL;
} else {
    $supplierId = (int) $supplierRow['supplier_id'];
    $supplierRows = $repository->serviceProfit([1, 2], null, null, $supplierId);
    $unexpectedSupplierRows = array_values(array_filter(
        $supplierRows,
        static fn (array $row): bool => (int) ($row['supplier_id'] ?? 0) !== $supplierId
    ));
    $check(
        $supplierRows !== [] && $unexpectedSupplierRows === [] && count($supplierRows) <= count($rows),
        'Service Profit supplier filter returns only the selected supplier without changing All Suppliers',
        [
            'supplier_id' => $supplierId,
            'all_rows' => count($rows),
            'filtered_rows' => count($supplierRows),
            'unexpected_rows' => count($unexpectedSupplierRows),
        ]
    );
}

$sharedReportsExecuted = true;
$sharedReportCounts = [];
try {
    $sharedReportCounts = [
        'gross_profit' => count($repository->grossProfitSummary([1, 2], null, null)),
        'branch_performance_services' => count(($repository->branchPerformance([1, 2], null, null))['services'] ?? []),
        'management_service_types' => count(($repository->managementSummary([1, 2], null, null))['serviceTypes'] ?? []),
        'airline_profitability' => count($repository->airlineWiseProfitability([1, 2], null, null)),
    ];
} catch (Throwable $exception) {
    $sharedReportsExecuted = false;
    $sharedReportCounts = ['error' => $exception->getMessage()];
}
$check(
    $sharedReportsExecuted,
    'Gross-profit, branch-performance, management, and airline-profit reports use the shared currency-safe calculation',
    $sharedReportCounts
);

$reportService = new \App\Services\ReportService($app);
$serviceProfitMethod = new ReflectionMethod($reportService, 'serviceProfitReport');
$serviceProfitMethod->setAccessible(true);
[$syntheticRows] = $serviceProfitMethod->invoke($reportService, [[
    'branch_name' => 'Noble Route',
    'booking_reference' => 'BK-000529',
    'booking_date' => '2026-07-27',
    'line_reference' => 'SV-001',
    'service_type' => 'air ticket',
    'supplier_name' => 'Mixed Currency Supplier',
    'currency' => 'AED',
    'payable_currency' => 'PKR',
    'receivable_amount' => 5150.00,
    'payable_original_amount' => 377483.00,
    'payable_amount' => round(377483 / 77, 2),
    'service_charge' => 248.00,
    'discount_amount' => 0.00,
]], ['AED' => 77.00, 'PKR' => 1.00]);
$synthetic = (array) ($syntheticRows[0] ?? []);
$check(
    ($synthetic['profit_snapshot'] ?? '') === '247.62'
    && ($synthetic['pkr_receivable_amount'] ?? '') === '396,550.00'
    && ($synthetic['pkr_payable_amount'] ?? '') === '377,483.00'
    && ($synthetic['pkr_profit_snapshot'] ?? '') === '19,067.00',
    'BK-000529 example resolves to AED 247.62 / PKR 19,067 profit instead of a false loss',
    $synthetic
);

$serviceProfitFooterMethod = new ReflectionMethod($reportService, 'serviceProfitFooterRows');
$serviceProfitFooterMethod->setAccessible(true);
$currencyFooterRows = $serviceProfitFooterMethod->invoke($reportService, [
    [
        'currency' => 'PKR',
        'receivable_amount' => '10,000.00',
        'payable_amount' => '8,000.00',
        'discount_amount' => '200.00',
        'profit_snapshot' => '2,000.00',
        'pkr_receivable_amount' => '10,000.00',
        'pkr_payable_amount' => '8,000.00',
        'pkr_profit_snapshot' => '2,000.00',
    ],
    [
        'currency' => 'AED',
        'receivable_amount' => '1,000.00',
        'payable_amount' => '800.00',
        'discount_amount' => '20.00',
        'profit_snapshot' => '200.00',
        'pkr_receivable_amount' => '77,000.00',
        'pkr_payable_amount' => '61,600.00',
        'pkr_profit_snapshot' => '15,400.00',
    ],
]);
$pkrFooter = (array) ($currencyFooterRows[0] ?? []);
$aedFooter = (array) ($currencyFooterRows[1] ?? []);
$check(
    count($currencyFooterRows) === 2
    && ($pkrFooter['_label'] ?? '') === 'Total PKR'
    && ($pkrFooter['receivable_amount'] ?? '') === '10,000.00'
    && ($pkrFooter['payable_amount'] ?? '') === '8,000.00'
    && ($pkrFooter['discount_amount'] ?? '') === '200.00'
    && ($pkrFooter['profit_snapshot'] ?? '') === '2,000.00'
    && ($pkrFooter['pkr_receivable_amount'] ?? '') === '87,000.00'
    && ($pkrFooter['pkr_payable_amount'] ?? '') === '69,600.00'
    && ($pkrFooter['pkr_profit_snapshot'] ?? '') === '17,400.00'
    && ($aedFooter['_label'] ?? '') === 'Total AED'
    && ($aedFooter['receivable_amount'] ?? '') === '1,000.00'
    && ($aedFooter['payable_amount'] ?? '') === '800.00'
    && ($aedFooter['discount_amount'] ?? '') === '20.00'
    && ($aedFooter['profit_snapshot'] ?? '') === '200.00'
    && ! isset($aedFooter['pkr_receivable_amount']),
    'Service Profit footer keeps PKR and AED native totals separate while consolidating PKR columns once',
    $currencyFooterRows
);

$bk529 = array_values(array_filter(
    $rows,
    static fn (array $row): bool => strtoupper((string) ($row['booking_reference'] ?? '')) === 'BK-000529'
));

if ($bk529 === []) {
    echo '[SKIP] BK-000529 is not present in this database; the generic mixed-currency check still ran.' . PHP_EOL;
} else {
    $bk529Profit = 0.0;
    foreach ($bk529 as $row) {
        $bk529Profit += (float) ($row['receivable_amount'] ?? 0) - (float) ($row['payable_amount'] ?? 0);
    }
    $check(
        $bk529Profit > 0,
        'BK-000529 no longer reports a false negative profit',
        ['invoice_currency_profit' => round($bk529Profit, 2), 'rows' => $bk529]
    );
}

if ($failures !== []) {
    fwrite(STDERR, PHP_EOL . 'Service-profit report regression failed: ' . implode('; ', $failures) . PHP_EOL);
    exit(1);
}

echo PHP_EOL . 'Service-profit report regression passed.' . PHP_EOL;
