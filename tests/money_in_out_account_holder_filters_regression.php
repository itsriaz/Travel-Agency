<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';

$app = \App\Core\App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');
$treasury = new \App\Repositories\TreasuryRepository($app);
$report = new \App\Repositories\ReportRepository($app);
$holders = (new \App\Repositories\MasterDataRepository($app))->activeRows('business_sources');
$accounts = $treasury->transferAccounts([1, 2]);
$account = null;
foreach ($accounts as $candidate) {
    if (($candidate['account_type'] ?? '') === 'cash' && ($candidate['currency'] ?? '') === 'PKR') {
        $account = $candidate;
        break;
    }
}
if ($account === null || count($holders) < 2) {
    throw new RuntimeException('Need one PKR cash account and two account holders for the filter regression.');
}

$branch = (int) $account['branch_id'];
$accountId = (int) $account['id'];
$firstHolder = (int) $holders[0]['id'];
$secondHolder = (int) $holders[1]['id'];
$today = date('Y-m-d');
$db->beginTransaction();
try {
    $base = [
        'branch_id' => $branch,
        'currency' => 'PKR',
        'treasury_account_id' => $accountId,
        'transaction_date' => $today,
        'created_by_user_id' => 1,
    ];
    $original = $treasury->saveDirectEntry($base + [
        'transaction_type' => 'adjustment_increase',
        'business_source_id' => $firstHolder,
        'amount' => 100,
    ], [$branch]);
    $treasury->correctDirectEntry($base + [
        'correction_transaction_id' => $original,
        'correction_reason' => 'Rollback-only filter regression',
        'transaction_type' => 'adjustment_increase',
        'business_source_id' => $firstHolder,
        'amount' => 120,
    ], 1, [$branch]);
    $treasury->saveDirectEntry($base + [
        'transaction_type' => 'adjustment_decrease',
        'business_source_id' => $firstHolder,
        'amount' => 20,
    ], [$branch]);
    $treasury->saveDirectEntry($base + [
        'transaction_type' => 'adjustment_increase',
        'business_source_id' => $secondHolder,
        'amount' => 50,
    ], [$branch]);
    $treasury->saveDirectEntry($base + [
        'transaction_type' => 'adjustment_increase',
        'amount' => 7,
    ], [$branch]);

    $rows = $report->directMoneyInOutLedger([$branch], $today, $today, $accountId, 'all', 'all', $firstHolder, 'PKR');
    $totals = \App\Services\DirectMoneyInOutSummary::byCurrency($rows);
    if (count($rows) !== 4 || round($totals['PKR']['in'] ?? 0) !== 120.0
        || round($totals['PKR']['out'] ?? 0) !== 20.0) {
        throw new RuntimeException('Account holder totals did not net the correction and payment correctly.');
    }
    $reportState = (new \App\Services\ReportService($app))->reportState([
        'report' => 'money_in_out_ledger',
        'branch_id' => $branch,
        'date_from' => $today,
        'date_to' => $today,
        'currency' => 'PKR',
        'treasury_account_id' => $accountId,
        'money_account_holder_id' => $firstHolder,
    ], [$branch], 1);
    $footer = $reportState['footerRows'][0] ?? [];
    $cards = array_column($reportState['summaryCards'] ?? [], 'value', 'label');
    if (count($reportState['footerRows'] ?? []) !== 1
        || (float) ($footer['money_in'] ?? 0) !== 220.0
        || (float) ($footer['money_out'] ?? 0) !== 120.0
        || (float) ($footer['balance'] ?? 0) !== 100.0
        || ($cards['MONEY IN / PKR'] ?? '') !== 'PKR 220.00'
        || ($cards['MONEY OUT / PKR'] ?? '') !== 'PKR 120.00') {
        throw new RuntimeException('Money In/Out summary and footer must total the visible movement columns and subtract them for balance.');
    }
    $inRows = $report->directMoneyInOutLedger([$branch], $today, $today, $accountId, 'in', 'all', $firstHolder, 'PKR');
    if (count($inRows) !== 3 || round(\App\Services\DirectMoneyInOutSummary::byCurrency($inRows)['PKR']['in'] ?? 0) !== 120.0) {
        throw new RuntimeException('Money In filter lost a correction reversal.');
    }
    $otherRows = $report->directMoneyInOutLedger([$branch], $today, $today, $accountId, 'all', 'all', $secondHolder, 'PKR');
    if (count($otherRows) !== 1 || round(\App\Services\DirectMoneyInOutSummary::byCurrency($otherRows)['PKR']['in'] ?? 0) !== 50.0) {
        throw new RuntimeException('Another account holder leaked into the selected holder total.');
    }
    $unlinkedRows = $report->directMoneyInOutLedger([$branch], $today, $today, $accountId, 'all', 'all', -1, 'PKR');
    if (round(\App\Services\DirectMoneyInOutSummary::byCurrency($unlinkedRows)['PKR']['in'] ?? 0) !== 7.0) {
        throw new RuntimeException('No Account Holder filter mixed unlinked entries with a holder.');
    }
    $separateCurrencies = \App\Services\DirectMoneyInOutSummary::byCurrency(array_merge($rows, [[
        'currency' => 'AED',
        'transaction_type' => 'adjustment_increase',
        'source_group' => 'posted',
        'debit_amount' => 25,
    ]]));
    if (round($separateCurrencies['PKR']['in'] ?? 0) !== 120.0 || round($separateCurrencies['AED']['in'] ?? 0) !== 25.0) {
        throw new RuntimeException('AED and PKR totals were combined.');
    }
    $activity = $treasury->recentTransfers([$branch], 20, 'direct', [
        'date_from' => $today,
        'date_to' => $today,
        'currency' => 'PKR',
        'account_id' => $accountId,
        'holder_id' => $firstHolder,
        'direction' => 'in',
    ]);
    if (count(array_filter($activity, static fn (array $row): bool => (int) $row['id'] === $original)) !== 1
        || count($activity) !== 2) {
        throw new RuntimeException('Recent activity did not preserve the original and replacement entries.');
    }

    echo '[PASS] holder, direction, date, currency, and cash-account filters' . PHP_EOL;
    echo '[PASS] correction reversal nets PKR 100 original to PKR 120 received' . PHP_EOL;
    echo '[PASS] footer shows PKR 220 Money In and PKR 120 Money Out, including the reversal; balance PKR 100' . PHP_EOL;
    echo '[PASS] second holder stays separate; PKR 20 paid out remains distinct' . PHP_EOL;
    echo '[PASS] entries without an account holder remain separately filterable' . PHP_EOL;
    echo '[PASS] AED and PKR remain separate in totals' . PHP_EOL;
} finally {
    $db->rollBack();
    echo 'ROLLBACK: no test entries retained' . PHP_EOL;
}
