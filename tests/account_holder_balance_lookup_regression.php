<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';

$app = \App\Core\App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');
$branchId = (int) $db->query('SELECT id FROM branches ORDER BY id LIMIT 1')->fetchColumn();
if ($branchId <= 0) {
    fwrite(STDERR, "Account-holder balance lookup regression requires a branch.\n");
    exit(1);
}

$originalCollation = (string) $db->query('SELECT @@collation_connection')->fetchColumn();
$db->beginTransaction();
try {
    $suffix = strtoupper(bin2hex(random_bytes(5)));
    $sourceName = 'CODEX HOLDER ' . $suffix;
    $createSource = $db->prepare(
        'INSERT INTO business_sources (code, name, is_system, is_active)
         VALUES (:code, :name, 0, 1)'
    );
    $createSource->execute(['code' => 'CH' . $suffix, 'name' => $sourceName]);
    $sourceId = (int) $db->lastInsertId();

    $createTransaction = $db->prepare(
        'INSERT INTO treasury_transactions (
            branch_id, business_source_id, transaction_type, transaction_date,
            currency, amount, narration, status
         ) VALUES (
            :branch_id, :business_source_id, :transaction_type, CURRENT_DATE(),
            :currency, :amount, :narration, "posted"
         )'
    );
    foreach ([
        [$sourceId, 'adjustment_increase', 'PKR', 100, 'Current account-holder entry'],
        [null, 'adjustment_increase', 'PKR', 50, 'Legacy entry for ' . strtolower($sourceName)],
        [null, 'adjustment_decrease', 'PKR', 20, 'Legacy entry for ' . $sourceName],
        [null, 'adjustment_increase', 'PKR', 70, 'Different account holder'],
        [$sourceId, 'adjustment_increase', 'AED', 30, 'Other currency'],
    ] as [$businessSourceId, $type, $currency, $amount, $narration]) {
        $createTransaction->execute([
            'branch_id' => $branchId,
            'business_source_id' => $businessSourceId,
            'transaction_type' => $type,
            'currency' => $currency,
            'amount' => $amount,
            'narration' => $narration,
        ]);
    }

    $db->exec('SET collation_connection = utf8mb4_bin');
    $balances = (new \App\Repositories\AccountHolderBalanceRepository($app))->availableBalances($branchId, $sourceId);
    $byCurrency = array_column($balances, null, 'currency');
    if (round((float) ($byCurrency['PKR']['available_amount'] ?? -1), 2) !== 130.0
        || round((float) ($byCurrency['AED']['available_amount'] ?? -1), 2) !== 30.0
        || count($balances) !== 2) {
        throw new RuntimeException('Mixed-collation lookup changed the ID-linked, legacy, or currency-specific balance: ' . json_encode($balances));
    }
    echo "[PASS] ID-linked and legacy balances remain separate by currency under utf8mb4_bin connection collation.\n";

    $resolveFunding = new ReflectionMethod(
        \App\Services\CustomerReceiptWorkspaceService::class,
        'resolveAccountHolderBalanceFunding'
    );
    try {
        $resolveFunding->invoke(
            new \App\Services\CustomerReceiptWorkspaceService($app),
            ['payment_method' => 'account_holder_balance'],
            $branchId,
            'PKR',
            25.0
        );
        throw new RuntimeException('An account-balance payment without a balance selection was accepted.');
    } catch (RuntimeException $exception) {
        if (! str_contains($exception->getMessage(), 'No money was posted.')) {
            throw $exception;
        }
    }
    echo "[PASS] A payment with no selected account balance fails before posting.\n";

    $validFunding = $resolveFunding->invoke(
        new \App\Services\CustomerReceiptWorkspaceService($app),
        [
            'payment_method' => 'account_holder_balance',
            'account_balance_mode' => 'account_holder_balance',
            'account_balance_source_currency' => 'PKR',
            'account_balance_business_source_id' => $sourceId,
        ],
        $branchId,
        'PKR',
        25.0
    );
    if (($validFunding['enabled'] ?? null) !== true
        || round((float) ($validFunding['source_amount'] ?? 0), 2) !== 25.0) {
        throw new RuntimeException('A valid account-balance payment was not accepted.');
    }
    echo "[PASS] A valid selected balance can still fund a payment.\n";

    try {
        $resolveFunding->invoke(
            new \App\Services\CustomerReceiptWorkspaceService($app),
            [
                'payment_method' => 'account_holder_balance',
                'account_balance_mode' => 'account_holder_balance',
                'account_balance_source_currency' => 'PKR',
                'account_balance_business_source_id' => $sourceId,
            ],
            $branchId,
            'PKR',
            200.0
        );
        throw new RuntimeException('An account-balance payment exceeded its available funds.');
    } catch (RuntimeException $exception) {
        if (! str_contains($exception->getMessage(), 'not enough')) {
            throw $exception;
        }
    }
    echo "[PASS] A selected balance cannot be overdrawn.\n";

    $noPayment = $resolveFunding->invoke(
        new \App\Services\CustomerReceiptWorkspaceService($app),
        ['payment_method' => 'account_holder_balance'],
        $branchId,
        'PKR',
        0.0
    );
    if (($noPayment['enabled'] ?? null) !== false) {
        throw new RuntimeException('A zero-payment booking save was treated as an account-balance payment.');
    }
    echo "[PASS] A zero-payment booking save remains allowed without an account-balance selection.\n";
} finally {
    $db->rollBack();
    if (preg_match('/^utf8mb4_[a-z0-9_]+$/', $originalCollation) === 1) {
        $db->exec('SET collation_connection = ' . $originalCollation);
    }
}
