<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';

$app = (isset($app) && $app instanceof \App\Core\App)
    ? $app
    : \App\Core\App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');

$treasuryRepository = new \App\Repositories\TreasuryRepository($app);

$failures = [];
$check = static function (string $label, bool $passed, string $details = '') use (&$failures): void {
    echo ($passed ? '[PASS] ' : '[FAIL] ') . $label . ($details !== '' ? ' - ' . $details : '') . PHP_EOL;

    if (! $passed) {
        $failures[] = $label . ($details !== '' ? ': ' . $details : '');
    }
};

echo 'Treasury opening balance correction regression' . PHP_EOL;
echo 'Started: ' . date(DATE_ATOM) . PHP_EOL . PHP_EOL;

$branchIds = [1, 2];
$branchId = 2;
$currency = 'AED';
$actorUserId = 1;
$accountName = 'Codex Regression ENBD ' . date('His');
$today = date('Y-m-d');

$db->beginTransaction();

try {
    $accountId = $treasuryRepository->saveAccount([
        'branch_id' => $branchId,
        'account_type' => 'bank',
        'account_name' => $accountName,
        'currency' => $currency,
        'opening_balance' => '100.00',
        'opening_balance_date' => $today,
        'is_active' => '1',
        'created_by_user_id' => $actorUserId,
    ], $branchIds);

    $statement = $db->prepare(
        'SELECT id, linked_account_id, account_code, opening_balance
         FROM treasury_accounts
         WHERE id = :id
         LIMIT 1'
    );
    $statement->execute(['id' => $accountId]);
    $accountRow = $statement->fetch(PDO::FETCH_ASSOC) ?: [];

    $check(
        'Treasury account uses a dedicated linked ledger account on create',
        (int) ($accountRow['linked_account_id'] ?? 0) > 0
            && strtoupper((string) ($accountRow['account_code'] ?? '')) !== 'BANK_CLEARING',
        json_encode($accountRow, JSON_UNESCAPED_SLASHES)
    );

    $treasuryRepository->saveDirectEntry([
        'branch_id' => $branchId,
        'transaction_type' => 'adjustment_increase',
        'transaction_date' => $today,
        'currency' => $currency,
        'amount' => '50.00',
        'treasury_account_id' => $accountId,
        'reference_no' => 'RG-OPENING-BAL',
        'narration' => 'Regression treasury deposit',
        'created_by_user_id' => $actorUserId,
    ], [$branchId]);

    $balanceAfterDeposit = $treasuryRepository->currentBalanceForAccountId($accountId);
    $check(
        'Direct treasury entry increases visible current balance',
        abs($balanceAfterDeposit - 150.00) < 0.01,
        'balance=' . number_format($balanceAfterDeposit, 2, '.', '')
    );

    $bankClearingStatement = $db->prepare(
        'SELECT id
         FROM chart_of_accounts
         WHERE code = :code
         LIMIT 1'
    );
    $bankClearingStatement->execute(['code' => 'BANK_CLEARING']);
    $bankClearingId = (int) ($bankClearingStatement->fetchColumn() ?: 0);

    if ($bankClearingId > 0) {
        $db->prepare(
            'UPDATE treasury_accounts
             SET linked_account_id = :linked_account_id
             WHERE id = :id'
        )->execute([
            'linked_account_id' => $bankClearingId,
            'id' => $accountId,
        ]);
    }

    $balanceWithStaleLink = $treasuryRepository->currentBalanceForAccountId($accountId);
    $check(
        'Current balance still sees historical money even if linked ledger id is stale',
        abs($balanceWithStaleLink - 150.00) < 0.01,
        'balance=' . number_format($balanceWithStaleLink, 2, '.', '')
    );

    $treasuryRepository->saveAccount([
        'id' => $accountId,
        'branch_id' => $branchId,
        'account_type' => 'bank',
        'account_name' => $accountName,
        'currency' => $currency,
        'opening_balance' => '0.00',
        'opening_balance_date' => $today,
        'is_active' => '1',
        'created_by_user_id' => $actorUserId,
    ], $branchIds);

    $postEditStatement = $db->prepare(
        'SELECT linked_account_id, opening_balance
         FROM treasury_accounts
         WHERE id = :id
         LIMIT 1'
    );
    $postEditStatement->execute(['id' => $accountId]);
    $postEditAccount = $postEditStatement->fetch(PDO::FETCH_ASSOC) ?: [];

    $balanceAfterOpeningCorrection = $treasuryRepository->currentBalanceForAccountId($accountId);
    $check(
        'Opening balance correction now deducts the removed opening amount from live balance',
        abs($balanceAfterOpeningCorrection - 50.00) < 0.01,
        'balance=' . number_format($balanceAfterOpeningCorrection, 2, '.', '')
    );
    $check(
        'Opening balance field updates while linked ledger is re-bound to the dedicated account',
        abs((float) ($postEditAccount['opening_balance'] ?? 0) - 0.00) < 0.01
            && (int) ($postEditAccount['linked_account_id'] ?? 0) > 0
            && (int) ($postEditAccount['linked_account_id'] ?? 0) !== $bankClearingId,
        json_encode($postEditAccount, JSON_UNESCAPED_SLASHES)
    );

    $correctionStatement = $db->prepare(
        'SELECT COUNT(*)
         FROM treasury_transactions
         WHERE reference_no LIKE :reference'
    );
    $correctionStatement->execute([
        'reference' => 'OPENING-CORR-' . $accountId . '-%',
    ]);
    $correctionCount = (int) ($correctionStatement->fetchColumn() ?: 0);

    $check(
        'Opening balance edit no longer posts an automatic preserve-balance treasury transaction',
        $correctionCount === 0,
        'count=' . $correctionCount
    );
} catch (Throwable $exception) {
    $check('Regression execution completed without exception', false, $exception->getMessage());
} finally {
    $db->rollBack();
}

echo PHP_EOL;
echo empty($failures)
    ? 'All treasury opening balance regression checks passed.' . PHP_EOL
    : 'Failures:' . PHP_EOL . ' - ' . implode(PHP_EOL . ' - ', $failures) . PHP_EOL;

return empty($failures) ? 0 : 1;
