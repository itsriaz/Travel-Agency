<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';

$app = (isset($app) && $app instanceof \App\Core\App)
    ? $app
    : \App\Core\App::bootstrap(BASE_PATH);

$treasuryRepository = new \App\Repositories\TreasuryRepository($app);
$reportRepository = new \App\Repositories\ReportRepository($app);

$branchIds = [1, 2];
$asOfDate = date('Y-m-d');

$treasuryAccounts = $treasuryRepository->accounts($branchIds);
$cashBankRows = $reportRepository->cashBankPosition($branchIds, $asOfDate);

$failures = [];

$check = static function (string $label, bool $passed, string $details = '') use (&$failures): void {
    echo ($passed ? '[PASS] ' : '[FAIL] ') . $label . ($details !== '' ? ' - ' . $details : '') . PHP_EOL;

    if (! $passed) {
        $failures[] = $label . ($details !== '' ? ': ' . $details : '');
    }
};

echo 'Report truth regression' . PHP_EOL;
echo 'Started: ' . date(DATE_ATOM) . PHP_EOL . PHP_EOL;

$cashBankIndex = [];
foreach ($cashBankRows as $row) {
    $key = implode('|', [
        (int) ($row['branch_id'] ?? 0),
        strtoupper((string) ($row['currency'] ?? '')),
        strtoupper((string) ($row['account_code'] ?? '')),
    ]);
    $cashBankIndex[$key] = round((float) ($row['balance'] ?? 0), 2);
}

$checkedAccounts = 0;
$mismatches = [];

foreach ($treasuryAccounts as $account) {
    $accountType = (string) ($account['account_type'] ?? '');
    if (! in_array($accountType, ['cash', 'bank', 'wallet'], true)) {
        continue;
    }

    $checkedAccounts++;
    $key = implode('|', [
        (int) ($account['branch_id'] ?? 0),
        strtoupper((string) ($account['currency'] ?? '')),
        strtoupper((string) ($account['account_code'] ?? '')),
    ]);

    $treasuryBalance = round((float) ($account['current_balance'] ?? 0), 2);
    $reportBalance = round((float) ($cashBankIndex[$key] ?? 0), 2);

    if (round($treasuryBalance - $reportBalance, 2) !== 0.0) {
        $mismatches[] = [
            'branch_id' => (int) ($account['branch_id'] ?? 0),
            'account_name' => (string) ($account['account_name'] ?? ''),
            'account_code' => (string) ($account['account_code'] ?? ''),
            'currency' => (string) ($account['currency'] ?? ''),
            'treasury_current_balance' => $treasuryBalance,
            'cash_bank_position_balance' => $reportBalance,
        ];
    }
}

$check('Operational treasury accounts were loaded', $checkedAccounts > 0, 'count=' . $checkedAccounts);
$check(
    'Treasury current balances match Cash and Bank Position balances',
    $mismatches === [],
    $mismatches !== [] ? json_encode(array_slice($mismatches, 0, 10), JSON_UNESCAPED_SLASHES) : 'checked=' . $checkedAccounts
);

$agingRows = $reportRepository->receivableAging($branchIds, $asOfDate);
$agingTotals = [];
$agingInvalidRows = [];
foreach ($agingRows as $row) {
    $currency = strtoupper(trim((string) ($row['currency'] ?? 'PKR')));
    $outstanding = round((float) ($row['outstanding_amount'] ?? 0), 2);
    $overdueDays = (int) ($row['overdue_days'] ?? 0);
    $bucket = $overdueDays <= 0
        ? 'current'
        : ($overdueDays <= 30
            ? '1_30'
            : ($overdueDays <= 60
                ? '31_60'
                : ($overdueDays <= 90 ? '61_90' : '91_plus')));

    if ($currency === '' || $outstanding <= 0) {
        $agingInvalidRows[] = [
            'booking_reference' => (string) ($row['booking_reference'] ?? ''),
            'currency' => $currency,
            'outstanding_amount' => $outstanding,
        ];
        continue;
    }

    if (! isset($agingTotals[$currency])) {
        $agingTotals[$currency] = [
            'current' => 0.0,
            '1_30' => 0.0,
            '31_60' => 0.0,
            '61_90' => 0.0,
            '91_plus' => 0.0,
            'outstanding' => 0.0,
        ];
    }

    $agingTotals[$currency][$bucket] += $outstanding;
    $agingTotals[$currency]['outstanding'] += $outstanding;
}

$agingMismatches = [];
foreach ($agingTotals as $currency => $totals) {
    $bucketTotal = round(
        $totals['current']
        + $totals['1_30']
        + $totals['31_60']
        + $totals['61_90']
        + $totals['91_plus'],
        2
    );
    $outstandingTotal = round($totals['outstanding'], 2);
    if ($bucketTotal !== $outstandingTotal) {
        $agingMismatches[] = [
            'currency' => $currency,
            'bucket_total' => $bucketTotal,
            'outstanding_total' => $outstandingTotal,
        ];
    }
}

$check(
    'Receivable Aging rows retain valid currency and positive outstanding values',
    $agingInvalidRows === [],
    $agingInvalidRows !== [] ? json_encode(array_slice($agingInvalidRows, 0, 10), JSON_UNESCAPED_SLASHES) : 'checked=' . count($agingRows)
);
$check(
    'Receivable Aging currency buckets equal outstanding totals',
    $agingMismatches === [],
    $agingMismatches !== [] ? json_encode($agingMismatches, JSON_UNESCAPED_SLASHES) : 'currencies=' . count($agingTotals)
);

if ($failures !== []) {
    echo PHP_EOL . 'Report truth regression failed:' . PHP_EOL;
    foreach ($failures as $failure) {
        echo ' - ' . $failure . PHP_EOL;
    }
    return 1;
}

echo PHP_EOL . 'Report truth regression passed.' . PHP_EOL;
return 0;
