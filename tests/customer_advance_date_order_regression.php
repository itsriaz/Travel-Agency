<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';

$repositorySource = (string) file_get_contents(BASE_PATH . '/app/Repositories/ReportRepository.php');
if (! str_contains($repositorySource, 'DATE(cra.allocated_at) AS entry_date')) {
    fwrite(STDERR, "Advance applications must use their allocation date, not the invoice due date.\n");
    exit(1);
}

$reflection = new ReflectionClass(\App\Services\ReportService::class);
$service = $reflection->newInstanceWithoutConstructor();
$format = $reflection->getMethod('customerAdvanceLedgerReport');
$sort = $reflection->getMethod('sortReportRowsNewestFirst');

$base = [
    'branch_id' => 1,
    'traveler_id' => 1,
    'branch_name' => 'Swat',
    'customer_name' => 'Test Customer',
    'currency' => 'PKR',
    'reference' => 'RCPT-TEST',
    'payment_method' => 'cash',
    'sort_order' => 10,
    'row_id' => 1,
    'receipt_id' => 1,
    'entry_date' => '2026-10-01',
    'entry_type' => 'Customer Advance Received',
    'received_amount' => 100,
    'applied_amount' => 0,
    'returned_amount' => 0,
];
$rows = [
    $base,
    array_merge($base, [
        'row_id' => 2,
        'allocation_id' => 2,
        'entry_date' => '2026-10-03',
        'entry_type' => 'Advance Applied to Invoice',
        'received_amount' => 0,
        'applied_amount' => 40,
        'sort_order' => 20,
    ]),
];
$availablePositions = [[
    'receipt_id' => 3,
    'receipt_no' => 'RCPT-CREDIT',
    'receipt_date' => '2026-10-02',
    'receipt_purpose' => 'booking_payment',
    'available_amount' => 25,
    'currency' => 'PKR',
    'branch_id' => 2,
    'branch_name' => 'Dubai',
    'customer_name' => 'Other Customer',
]];

[$displayRows] = $format->invoke($service, $rows, $availablePositions, 'PKR');
$displayRows = $sort->invoke($service, 'customer_advance_ledger', $displayRows);

if (array_column($displayRows, 'entry_date') !== ['2026-10-03', '2026-10-02', '2026-10-01']
    || array_column($displayRows, 'entry_type') !== [
        'Advance Applied to Invoice', 'Booking Credit Available', 'Customer Advance Received',
    ]
    || (float) ($displayRows[0]['balance_amount'] ?? -1) !== 60.0
    || (float) ($displayRows[2]['balance_amount'] ?? -1) !== 100.0
) {
    fwrite(STDERR, 'Customer advance dates or running balances are incorrect: ' . json_encode(array_map(
        static fn (array $row): array => [
            'date' => $row['entry_date'] ?? '',
            'type' => $row['entry_type'] ?? '',
            'balance' => $row['balance_amount'] ?? '',
        ],
        $displayRows
    )) . PHP_EOL);
    exit(1);
}

echo '[PASS] Customer advance rows display newest transaction date first across branches without changing running balances.' . PHP_EOL;
