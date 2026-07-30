<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';

$app = (isset($app) && $app instanceof \App\Core\App)
    ? $app
    : \App\Core\App::bootstrap(BASE_PATH);

$failures = [];
$check = static function (string $label, bool $passed, string $details = '') use (&$failures): void {
    echo ($passed ? '[PASS] ' : '[FAIL] ') . $label . ($details !== '' ? ' - ' . $details : '') . PHP_EOL;
    if (! $passed) {
        $failures[] = $label . ($details !== '' ? ': ' . $details : '');
    }
};

echo 'Cash / Bank amount filter regression' . PHP_EOL;
echo 'Started: ' . date(DATE_ATOM) . PHP_EOL . PHP_EOL;

$repository = new \App\Repositories\ReportRepository($app);
$allRows = $repository->cashBankLedger([1, 2], null, null);
$sampleAmount = null;
foreach ($allRows as $row) {
    $candidate = max(
        round((float) ($row['debit_amount'] ?? 0), 2),
        round((float) ($row['credit_amount'] ?? 0), 2)
    );
    if ($candidate > 0) {
        $sampleAmount = $candidate;
        break;
    }
}

$check('Cash / Bank ledger rows are available for a live filter check', $sampleAmount !== null, 'rows=' . count($allRows));

if ($sampleAmount !== null) {
    $filteredRows = $repository->cashBankLedger([1, 2], null, null, '', 'all', $sampleAmount);
    $invalidMatches = array_values(array_filter($filteredRows, static function (array $row) use ($sampleAmount): bool {
        $debit = round((float) ($row['debit_amount'] ?? 0), 2);
        $credit = round((float) ($row['credit_amount'] ?? 0), 2);

        return abs($debit - $sampleAmount) >= 0.005 && abs($credit - $sampleAmount) >= 0.005;
    }));

$check(
    'Amount filter matches the exact value in either Debit or Credit',
    $filteredRows !== [] && $invalidMatches === [],
    'amount=' . number_format($sampleAmount, 2) . ', matches=' . count($filteredRows)
);
}

$service = new \App\Services\ReportService($app);
$amountFilterMethod = new ReflectionMethod($service, 'filterRowsByDebitCreditAmount');
$amountFilterMethod->setAccessible(true);
$combinedAmountRows = $amountFilterMethod->invoke($service, 'cash_bank_ledger', [
    ['reference' => 'DEBIT-MATCH', 'debit_amount' => '890.00', 'credit_amount' => '0.00'],
    ['reference' => 'CREDIT-MATCH', 'debit_amount' => '0.00', 'credit_amount' => '600.00'],
    ['reference' => 'NO-MATCH', 'debit_amount' => '100.00', 'credit_amount' => '50.00'],
], 890.00, 600.00);
$check(
    'Combined Debit and Credit filters match either side of one-sided cash/bank rows',
    array_column($combinedAmountRows, 'reference') === ['DEBIT-MATCH', 'CREDIT-MATCH']
);

$reportMethod = new ReflectionMethod($service, 'cashBankLedgerReport');
$reportMethod->setAccessible(true);
[$newestRows] = $reportMethod->invoke($service, $allRows, 'desc');
[$oldestRows] = $reportMethod->invoke($service, $allRows, 'asc');
[$legacyCorrectionRows] = $reportMethod->invoke($service, [[
    'treasury_account_id' => 999999,
    'branch_name' => 'Regression Branch',
    'entry_date' => '2026-07-27',
    'currency' => 'AED',
    'account_group' => 'Cash',
    'account_name' => 'Cash-UAE-AED',
    'source_type' => 'customer_advance_return_correction_reversal',
    'party_name' => 'ADVANCE',
    'reference' => 'RCPT-LEGACY',
    'description' => 'Correction reversal: Cash or bank returned to customer',
    'debit_amount' => 890,
    'credit_amount' => 0,
    'line_id' => 999999,
]], 'desc');

$check(
    'Historical advance-return reversals identify the cash/bank account receiving restored funds',
    (string) ($legacyCorrectionRows[0]['description'] ?? '')
        === 'Correction reversal: Funds restored to Cash-UAE-AED'
);

$datesAreOrdered = static function (array $rows, string $direction): bool {
    $lastByAccount = [];
    foreach ($rows as $row) {
        $key = implode('|', [
            (string) ($row['branch_name'] ?? ''),
            (string) ($row['account_name'] ?? ''),
            (string) ($row['currency'] ?? ''),
        ]);
        $date = (string) ($row['entry_date'] ?? '');
        if (isset($lastByAccount[$key])) {
            if ($direction === 'desc' && $date > $lastByAccount[$key]) {
                return false;
            }
            if ($direction === 'asc' && $date < $lastByAccount[$key]) {
                return false;
            }
        }
        $lastByAccount[$key] = $date;
    }

    return true;
};

$check('Newest-first date order is deterministic within each cash/bank account', $datesAreOrdered($newestRows, 'desc'));
$check('Oldest-first date order is deterministic within each cash/bank account', $datesAreOrdered($oldestRows, 'asc'));

$view = file_get_contents(BASE_PATH . '/app/Views/reports/index.php') ?: '';
$javascript = file_get_contents(BASE_PATH . '/public/assets/js/reports.js') ?: '';
$check(
    'Financial ledgers expose separate exact Debit and Credit amount fields',
    str_contains($view, 'name="debit_amount"')
        && str_contains($view, 'name="credit_amount"')
        && str_contains($view, '<span>Debit Amount</span>')
        && str_contains($view, '<span>Credit Amount</span>')
);
$check('Cash / Bank report exposes date ordering', str_contains($view, 'name="cash_bank_date_order"'));
$check(
    'Amount typing triggers the existing debounced report refresh',
    str_contains($view, 'data-report-filter="debounced-amount"')
        && str_contains($javascript, 'amountFilters.forEach')
        && str_contains($javascript, 'scheduleSubmit(350)')
);

if ($failures !== []) {
    echo PHP_EOL . 'Cash / Bank amount filter regression failed:' . PHP_EOL;
    foreach ($failures as $failure) {
        echo ' - ' . $failure . PHP_EOL;
    }
    exit(1);
}

echo PHP_EOL . 'Cash / Bank amount filter regression passed.' . PHP_EOL;
