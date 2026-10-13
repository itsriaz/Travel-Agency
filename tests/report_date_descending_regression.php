<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';

$app = (isset($app) && $app instanceof \App\Core\App)
    ? $app
    : \App\Core\App::bootstrap(BASE_PATH);
$service = new \App\Services\ReportService($app);
$reflection = new ReflectionClass($service);
$reports = $reflection->getConstant('REPORTS');
$dateKeys = $reflection->getConstant('REPORT_DATE_KEYS');
$undated = [
    'cash_bank_position', 'management_summary', 'accounting_integrity',
    'branch_performance', 'bsp_settlement_summary', 'ticket_tax_vat_summary',
    'airline_wise_profitability', 'branch_wise_ticketing_summary',
];
$failures = [];
$check = static function (string $label, bool $passed) use (&$failures): void {
    echo ($passed ? '[PASS] ' : '[FAIL] ') . $label . PHP_EOL;
    if (! $passed) {
        $failures[] = $label;
    }
};

$check('Every report is classified as dated or undated',
    array_diff(array_keys($reports), array_merge(array_keys($dateKeys), $undated)) === []
    && array_diff(array_keys($dateKeys), array_keys($reports)) === []);

$sort = $reflection->getMethod('sortReportRowsNewestFirst');
$sort->setAccessible(true);
foreach ($dateKeys as $report => $dateKey) {
    $input = [
        [$dateKey => '2026-10-01', 'branch_name' => 'Branch 1', 'reference' => 'old', 'balance_amount' => '10'],
        [$dateKey => '2026-10-03', 'branch_name' => 'Branch 2', 'reference' => 'new', 'balance_amount' => '30'],
        [$dateKey => '2026-10-02', 'branch_name' => 'Branch 1', 'reference' => 'middle', 'balance_amount' => '20'],
        [$dateKey => '2026-10-03', 'branch_name' => 'Branch 1', 'reference' => 'same-day', 'balance_amount' => '40'],
        [$dateKey => 'N/A', 'branch_name' => 'Branch 2', 'reference' => 'undated', 'balance_amount' => '50'],
    ];
    $output = $sort->invoke($service, $report, $input);
    $check($report . ' sorts across branches by date only',
        array_column($output, 'reference') === ['new', 'same-day', 'middle', 'old', 'undated']
        && array_column($output, 'balance_amount') === ['30', '40', '20', '10', '50']);
}

$timestampRows = [
    ['event_time' => '2026-10-03 09:00:00', 'reference' => 'early'],
    ['event_time' => '2026-10-03 12:00:00', 'reference' => 'late'],
    ['event_time' => '02/10/2026', 'reference' => 'previous'],
];
$check('Timestamped rows sort newest first',
    array_column($sort->invoke($service, 'finance_audit_trail', $timestampRows), 'reference')
    === ['late', 'early', 'previous']);

$filter = $reflection->getMethod('validatedFilters');
$filter->setAccessible(true);
$filters = $filter->invoke($service, ['report' => 'cash_bank_ledger', 'cash_bank_date_order' => 'asc'], [1, 2]);
$check('Cash / Bank ignores obsolete oldest-first links', $filters['cashBankDateOrder'] === 'desc');

$register = $reflection->getMethod('issueReissueRefundRegisterReport');
$register->setAccessible(true);
[$registerRows] = $register->invoke($service, [[
    'booking_id' => 1,
    'transaction_date' => '2026-10-03',
    'transaction_type' => 'Issue',
    'booking_reference' => 'BK-TEST',
]]);
$check('Issue / Reissue / Refund rows retain their transaction date',
    ($registerRows[0]['transaction_date'] ?? null) === '2026-10-03');

if (in_array('--live', $argv, true)) {
    $dateValue = $reflection->getMethod('reportDateSortValue');
    $dateValue->setAccessible(true);
    foreach ($reports as $report => $label) {
        $state = $service->reportState(['report' => $report], [1, 2], 1);
        $rows = (array) ($state['rows'] ?? []);
        $dateKey = $dateKeys[$report] ?? null;
        if ($dateKey === null || $rows === []) {
            echo '[INFO] ' . $report . ': ' . count($rows) . ' rows; no dated rows to check' . PHP_EOL;
            continue;
        }

        $datedCount = 0;
        $lastDate = null;
        $ordered = true;
        foreach ($rows as $row) {
            $date = $dateValue->invoke(null, $row[$dateKey] ?? null);
            if ($date !== '') {
                $datedCount++;
            }
            if ($lastDate !== null && strcmp($date, $lastDate) > 0) {
                $ordered = false;
            }
            $lastDate = $date;
        }
        $check($report . ' live rows have dates and descend across branches',
            $datedCount > 0 && $ordered);
        echo '[INFO] ' . $report . ': ' . count($rows) . ' rows, ' . $datedCount . ' dated' . PHP_EOL;
    }
}

if ($failures !== []) {
    exit(1);
}

echo 'Report date descending regression passed.' . PHP_EOL;
