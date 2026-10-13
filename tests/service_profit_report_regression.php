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
[$syntheticRows, $syntheticProfitTotals] = $serviceProfitMethod->invoke($reportService, [[
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
    'service_charge_currency' => 'AED',
    'discount_amount' => 0.00,
]], ['AED' => 77.00, 'PKR' => 1.00]);
$synthetic = (array) ($syntheticRows[0] ?? []);
$check(
    ($synthetic['profit_snapshot'] ?? '') === '248'
    && ($synthetic['pkr_receivable_amount'] ?? '') === '396,550'
    && ($synthetic['pkr_payable_amount'] ?? '') === '377,483'
    && ($synthetic['pkr_profit_snapshot'] ?? '') === '19,067'
    && abs((float) ($syntheticProfitTotals['AED'] ?? 0) - 247.62) < 0.01,
    'BK-000529 retains its existing profit calculation and detail display',
    $synthetic
);

$summaryCardsMethod = new ReflectionMethod($reportService, 'serviceProfitSummaryCards');
$summaryCardsMethod->setAccessible(true);
$summaryCards = $summaryCardsMethod->invoke(
    $reportService,
    ['AED' => 247.62, 'PKR' => 800.00],
    ['AED' => 50.10, 'PKR' => 400.00, 'USD' => 25.00],
    '',
    true
);
$check(
    array_column($summaryCards, 'label') === [
        'Service Profit / AED', 'Recorded Expenses / AED', 'Result After Recorded Expenses / AED',
        'Service Profit / PKR', 'Recorded Expenses / PKR', 'Result After Recorded Expenses / PKR',
        'Service Profit / USD', 'Recorded Expenses / USD', 'Result After Recorded Expenses / USD',
    ]
    && array_column($summaryCards, 'value') === [
        'AED 247.62', 'AED 50.10', 'AED 197.52',
        'PKR 800.00', 'PKR 400.00', 'PKR 400.00',
        'USD 0.00', 'USD 25.00', 'USD -25.00',
    ],
    'Summary shows exact native-currency profit less recorded expenses without double-counting service charges',
    $summaryCards
);
$filteredCards = $summaryCardsMethod->invoke(
    $reportService, ['AED' => 247.62], ['AED' => 50.10], 'AED', false
);
$check(
    $filteredCards === [['label' => 'Service Profit / AED', 'value' => 'AED 247.62']],
    'Filtered service activity never presents unallocated agency expenses or a misleading result',
    $filteredCards
);

$timelineMethod = new ReflectionMethod($reportService, 'serviceProfitTimelineRows');
$timelineMethod->setAccessible(true);
$timelineSource = $repository->serviceProfit([1, 2], null, null, 0, 0, '', '', '', true);
$timelineRows = $timelineMethod->invoke($reportService, $timelineSource, null, null);
$sourceTotals = ['receivable' => 0.0, 'payable' => 0.0, 'service' => 0.0];
$timelineTotals = $sourceTotals;
foreach ($timelineSource as $row) {
    $sourceTotals['receivable'] += (float) ($row['receivable_amount'] ?? 0);
    $sourceTotals['payable'] += (float) ($row['payable_amount'] ?? 0);
    $sourceTotals['service'] += (float) ($row['service_charge'] ?? 0);
}
foreach ($timelineRows as $row) {
    $timelineTotals['receivable'] += (float) ($row['receivable_amount'] ?? 0);
    $timelineTotals['payable'] += (float) ($row['payable_amount'] ?? 0);
    $timelineTotals['service'] += (float) ($row['service_charge'] ?? 0);
}
$check(
    abs($sourceTotals['receivable'] - $timelineTotals['receivable']) < 0.02
    && abs($sourceTotals['payable'] - $timelineTotals['payable']) < 0.02
    && abs($sourceTotals['service'] - $timelineTotals['service']) < 0.02,
    'Dated cancellation rows preserve all receivable, payable, profit, and service totals',
    ['source' => $sourceTotals, 'timeline' => $timelineTotals]
);

$datedCancellation = null;
foreach ($timelineSource as $row) {
    $payload = json_decode((string) ($row['cancellation_payload_json'] ?? ''), true);
    if ((int) ($row['cancellation_event_id'] ?? 0) > 0
        && (string) ($row['cancellation_event_date'] ?? '') !== (string) ($row['booking_date'] ?? '')
        && is_array($payload)
        && isset($payload['prior_customer_due_amount'], $payload['prior_supplier_obligation_gross_amount'])) {
        $datedCancellation = $row;
        break;
    }
}
if ($datedCancellation === null) {
    echo '[SKIP] No separately dated financial cancellation exists for the date-filter regression.' . PHP_EOL;
} else {
    $reference = (string) $datedCancellation['booking_reference'];
    $eventDate = (string) $datedCancellation['cancellation_event_date'];
    $eventSource = $repository->serviceProfit([1, 2], $eventDate, $eventDate, 0, 0, '', $reference, '', true);
    $eventRows = $timelineMethod->invoke($reportService, $eventSource, $eventDate, $eventDate);
    $eventMatches = array_values(array_filter($eventRows, static fn (array $row): bool =>
        (string) ($row['line_reference'] ?? '') === (string) $datedCancellation['line_reference']
        && (string) ($row['booking_date'] ?? '') === $eventDate
        && str_contains((string) ($row['service_type'] ?? ''), 'cancellation adjustment')
    ));
    $check(
        count($eventMatches) === 1
        && (float) ($eventMatches[0]['service_charge'] ?? -1) === 0.0,
        'Cancellation-date filter includes the adjustment without repeating the original service amount',
        ['booking' => $reference, 'booking_date' => $datedCancellation['booking_date'],
            'event_date' => $eventDate, 'adjustment_count' => count($eventMatches)]
    );
}

$mixedCancellation = null;
foreach ($timelineSource as $row) {
    if ((int) ($row['cancellation_event_id'] ?? 0) > 0
        && (string) ($row['payable_currency'] ?? '') !== (string) ($row['currency'] ?? '')) {
        $mixedCancellation = $row;
        break;
    }
}
if ($mixedCancellation === null) {
    echo '[SKIP] No mixed-currency cancellation exists for the supplier-cost regression.' . PHP_EOL;
} else {
    $mixedParts = array_values(array_filter($timelineRows, static fn (array $row): bool =>
        (string) ($row['booking_reference'] ?? '') === (string) $mixedCancellation['booking_reference']
        && (string) ($row['line_reference'] ?? '') === (string) $mixedCancellation['line_reference']
    ));
    $sourceSupplierCost = (float) ($mixedCancellation['payable_original_amount'] ?? 0);
    $splitSupplierCost = array_sum(array_map(
        static fn (array $row): float => (float) ($row['payable_original_amount'] ?? 0), $mixedParts
    ));
    $check(
        count($mixedParts) >= 2
        && abs($sourceSupplierCost - $splitSupplierCost) < 0.01
        && count(array_unique(array_column($mixedParts, 'payable_currency'))) === 1,
        'Mixed-currency cancellation preserves the original supplier-currency cost across dates',
        ['booking' => $mixedCancellation['booking_reference'],
            'currency' => $mixedCancellation['payable_currency'],
            'source_cost' => $sourceSupplierCost, 'split_cost' => $splitSupplierCost]
    );
}

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
    && ($pkrFooter['receivable_amount'] ?? '') === '10,000'
    && ($pkrFooter['payable_amount'] ?? '') === '8,000'
    && ($pkrFooter['discount_amount'] ?? '') === '200'
    && ($pkrFooter['profit_snapshot'] ?? '') === '2,000'
    && ($pkrFooter['pkr_receivable_amount'] ?? '') === '87,000'
    && ($pkrFooter['pkr_payable_amount'] ?? '') === '69,600'
    && ($pkrFooter['pkr_profit_snapshot'] ?? '') === '17,400'
    && ($aedFooter['_label'] ?? '') === 'Total AED'
    && ($aedFooter['receivable_amount'] ?? '') === '1,000'
    && ($aedFooter['payable_amount'] ?? '') === '800'
    && ($aedFooter['discount_amount'] ?? '') === '20'
    && ($aedFooter['profit_snapshot'] ?? '') === '200'
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
