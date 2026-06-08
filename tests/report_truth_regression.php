<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

require BASE_PATH . '/app/Helpers/functions.php';
require BASE_PATH . '/app/Core/bootstrap.php';

$app = \App\Core\App::bootstrap(BASE_PATH);

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

if ($failures !== []) {
    echo PHP_EOL . 'Report truth regression failed:' . PHP_EOL;
    foreach ($failures as $failure) {
        echo ' - ' . $failure . PHP_EOL;
    }
    exit(1);
}

echo PHP_EOL . 'Report truth regression passed.' . PHP_EOL;
