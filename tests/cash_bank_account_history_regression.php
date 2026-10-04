<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/Helpers/functions.php';
require BASE_PATH . '/app/Core/bootstrap.php';

$app = \App\Core\App::bootstrap(BASE_PATH);
$service = new \App\Services\ReportService($app);
$method = new ReflectionMethod($service, 'cashBankLedgerReport');
$method->setAccessible(true);

$accounts = [[
    'id' => 7,
    'currency' => 'PKR',
    'opening_balance' => '200.00',
]];
$journalRows = [
    ['treasury_account_id' => 7, 'entry_date' => '2026-01-01', 'currency' => 'PKR', 'source_type' => 'customer_receipt_recorded', 'debit_amount' => 300, 'credit_amount' => 0, 'line_id' => 1],
    ['treasury_account_id' => 7, 'entry_date' => '2026-02-01', 'currency' => 'PKR', 'source_type' => 'supplier_payment_recorded', 'debit_amount' => 0, 'credit_amount' => 1000, 'line_id' => 2],
    ['treasury_account_id' => 7, 'entry_date' => '2026-02-02', 'currency' => 'PKR', 'source_type' => 'customer_receipt_recorded', 'debit_amount' => 2000, 'credit_amount' => 0, 'line_id' => 3],
];

[$rows, $cards] = $method->invoke($service, $journalRows, 'asc', $accounts, '2026-02-01', 'all', null, 7, 'PKR');
$cardValues = [];
foreach ($cards as $card) {
    $cardValues[(string) $card['label']] = (string) $card['value'];
}
if (count($rows) !== 2
    || (string) $rows[0]['balance_amount'] !== '-500'
    || (string) $rows[1]['balance_amount'] !== '1,500'
    || ($cardValues['Opening / PKR'] ?? '') !== 'PKR 500'
    || ($cardValues['Closing / PKR'] ?? '') !== 'PKR 1,500') {
    throw new RuntimeException('Date-filtered account history lost its opening or running balance.');
}

[$customerRows] = $method->invoke($service, $journalRows, 'asc', $accounts, '2026-02-01', 'customer', null, 7, 'PKR');
if (count($customerRows) !== 1 || (string) $customerRows[0]['balance_amount'] !== '1,500') {
    throw new RuntimeException('Source-filtered history did not retain hidden supplier movements in its balance.');
}

$repository = new \App\Repositories\ReportRepository($app);
$accountOptions = $repository->treasuryLedgerAccountOptions([1, 2]);
if ($accountOptions === []) {
    throw new RuntimeException('No local treasury accounts are available for the read-only check.');
}
$account = $accountOptions[0];

$positions = [];
foreach ($repository->cashBankPosition([1, 2], date('Y-m-d')) as $position) {
    $key = (int) $position['branch_id'] . '|'
        . (string) $position['currency'] . '|'
        . (string) $position['account_code'];
    $positions[$key] = (float) $position['balance'];
}
foreach ($accountOptions as $candidate) {
    if ((int) ($candidate['is_active'] ?? 0) !== 1) {
        continue;
    }
    $candidateRows = $repository->cashBankLedger(
        [1, 2], null, date('Y-m-d'), (string) $candidate['currency'], 'all', null, (int) $candidate['id']
    );
    $journalBalance = (float) ($candidate['opening_balance'] ?? 0);
    foreach ($candidateRows as $candidateRow) {
        $journalBalance += (float) $candidateRow['debit_amount'] - (float) $candidateRow['credit_amount'];
    }
    $key = (int) $candidate['branch_id'] . '|'
        . (string) $candidate['currency'] . '|'
        . (string) $candidate['account_code'];
    if (abs($journalBalance - ($positions[$key] ?? 0.0)) > 0.01) {
        $bySource = [];
        foreach ($candidateRows as $candidateRow) {
            $source = (string) ($candidateRow['source_type'] ?? '');
            $bySource[$source] = ($bySource[$source] ?? 0.0)
                + (float) $candidateRow['debit_amount'] - (float) $candidateRow['credit_amount'];
        }
        $lineCounts = array_count_values(array_map(static fn (array $row): int => (int) ($row['line_id'] ?? 0), $candidateRows));
        $duplicateLines = array_filter($lineCounts, static fn (int $count): bool => $count > 1);
        throw new RuntimeException('Detailed movements differ from Cash and Bank Position for account '
            . (int) $candidate['id'] . ': detail=' . $journalBalance . ', position=' . ($positions[$key] ?? 0)
            . ', by_source=' . json_encode($bySource, JSON_THROW_ON_ERROR)
            . ', duplicate_lines=' . json_encode(array_slice($duplicateLines, 0, 8, true), JSON_THROW_ON_ERROR));
    }
}

$accountId = (int) $account['id'];
$ledgerRows = $repository->cashBankLedger([1, 2], null, null, (string) $account['currency'], 'all', null, $accountId);
foreach ($ledgerRows as $row) {
    if ((int) $row['treasury_account_id'] !== $accountId) {
        throw new RuntimeException('Account filter returned a different treasury account.');
    }
}

$report = $service->reportState([
    'report' => 'cash_bank_ledger',
    'treasury_account_id' => $accountId,
    'date_from' => '2026-10-01',
], [1, 2], 10);
if ((int) ($report['filters']['treasuryAccountId'] ?? 0) !== $accountId) {
    throw new RuntimeException('The report did not retain the selected cash/bank account.');
}

foreach ($accountOptions as $candidate) {
    $candidateId = (int) $candidate['id'];
    if ((int) ($candidate['is_active'] ?? 0) !== 1
        || $repository->unjournaledTreasuryTransferCount([1, 2], $candidateId) !== 0) {
        continue;
    }
    $fullReport = $service->reportState([
        'report' => 'cash_bank_ledger',
        'treasury_account_id' => $candidateId,
    ], [1, 2], 10);
    $closing = null;
    foreach ($fullReport['summaryCards'] as $card) {
        if ((string) ($card['label'] ?? '') === 'Closing / ' . (string) $candidate['currency']) {
            $closing = (float) str_replace(',', '', substr((string) $card['value'], 4));
            break;
        }
    }
    $treasuryBalance = (new \App\Repositories\TreasuryRepository($app))->currentBalanceForAccountId($candidateId);
    if ($closing === null || abs($closing - $treasuryBalance) > 0.01) {
        throw new RuntimeException('Full journal-backed history does not match the treasury balance for account ' . $candidateId . '.');
    }
    break;
}

try {
    $service->reportState([
        'report' => 'cash_bank_ledger',
        'branch_id' => (int) ($account['branch_id'] ?? 0) === 1 ? 2 : 1,
        'treasury_account_id' => $accountId,
    ], [1, 2], 10);
    throw new RuntimeException('A cross-branch account filter was accepted.');
} catch (RuntimeException $exception) {
    if ($exception->getMessage() === 'A cross-branch account filter was accepted.') {
        throw $exception;
    }
}

echo '[PASS] Cash/bank history preserves opening, date-filtered running balance, source-filtered balance, and account isolation.' . PHP_EOL;
