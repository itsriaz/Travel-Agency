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
$accountingRepository = new \App\Repositories\AccountingRepository($app);

$failures = [];
$check = static function (string $label, bool $passed, string $details = '') use (&$failures): void {
    echo ($passed ? '[PASS] ' : '[FAIL] ') . $label . ($details !== '' ? ' - ' . $details : '') . PHP_EOL;

    if (! $passed) {
        $failures[] = $label . ($details !== '' ? ': ' . $details : '');
    }
};

echo 'Treasury receipt balance de-dup regression' . PHP_EOL;
echo 'Started: ' . date(DATE_ATOM) . PHP_EOL . PHP_EOL;

$branchId = (int) $db->query('SELECT id FROM branches WHERE is_active = 1 ORDER BY id ASC LIMIT 1')->fetchColumn();
$actorUserId = (int) $db->query(
    'SELECT u.id
     FROM users u
     INNER JOIN roles r ON r.id = u.role_id
     WHERE u.is_active = 1
       AND r.code IN ("super_admin", "branch_admin")
     ORDER BY CASE WHEN r.code = "super_admin" THEN 0 ELSE 1 END, u.id
     LIMIT 1'
)->fetchColumn();

if ($branchId <= 0 || $actorUserId <= 0) {
    echo '[INFO] An active branch and financial admin are required for this rollback-only probe.' . PHP_EOL;
    return 0;
}

$token = date('His') . random_int(1000, 9999);
$today = date('Y-m-d');
$currency = 'USD';
$amount = 75.00;

$db->beginTransaction();

try {
    $accountId = $treasuryRepository->saveAccount([
        'branch_id' => $branchId,
        'account_type' => 'cash',
        'account_name' => 'Receipt DeDup Cash ' . $token,
        'currency' => $currency,
        'opening_balance' => '0.00',
        'opening_balance_date' => $today,
        'is_active' => '1',
        'created_by_user_id' => $actorUserId,
    ], [$branchId]);

    $db->prepare(
        'UPDATE treasury_accounts
         SET account_code = "CASH_ON_HAND"
         WHERE id = :id'
    )->execute(['id' => $accountId]);

    $receiptColumns = array_map(
        static fn (array $row): string => (string) ($row['Field'] ?? ''),
        $db->query('SHOW COLUMNS FROM customer_receipts')->fetchAll(PDO::FETCH_ASSOC) ?: []
    );
    $hasColumn = static fn (string $name): bool => in_array($name, $receiptColumns, true);

    $receiptPayload = [
        'branch_id' => $branchId,
        'booking_reference' => 'TREASURY-DEDUP-' . $token,
        'receipt_no' => 'RCPT-DEDUP-' . $token,
        'receipt_date' => $today,
        'currency' => $currency,
        'received_amount' => $amount,
        'allocated_amount' => 0.00,
        'unallocated_amount' => $amount,
        'payment_method' => 'cash',
        'reference_number' => 'TREASURY-DEDUP-' . $token,
        'bank_card_detail' => null,
        'charges_amount' => 0.00,
        'status' => 'posted',
        'remarks' => 'Rollback-only treasury receipt de-dup regression',
        'treasury_account_id' => $accountId,
        'tendered_amount' => $amount,
        'returned_amount' => 0.00,
        'receipt_purpose' => 'booking_payment',
        'exchange_rate_to_booking' => 1.0,
        'created_by_user_id' => $actorUserId,
        'traveler_id' => null,
        'business_source_id' => null,
    ];

    $receiptColumnsForInsert = [];
    $receiptParams = [];
    foreach ($receiptPayload as $column => $value) {
        if (! $hasColumn($column)) {
            continue;
        }

        $receiptColumnsForInsert[] = $column;
        $receiptParams[$column] = $value;
    }

    $receiptStatement = $db->prepare(sprintf(
        'INSERT INTO customer_receipts (%s) VALUES (%s)',
        implode(', ', $receiptColumnsForInsert),
        implode(', ', array_map(static fn (string $column): string => ':' . $column, $receiptColumnsForInsert))
    ));
    $receiptStatement->execute($receiptParams);
    $receiptId = (int) $db->lastInsertId();

    $accountingRepository->postJournalEntry([
        'branch_id' => $branchId,
        'booking_reference' => 'TREASURY-DEDUP-' . $token,
        'source_type' => 'customer_receipt_recorded',
        'source_reference' => 'RCPT-DEDUP-' . $token,
        'entry_date' => $today,
        'currency' => $currency,
        'narration' => 'Rollback-only treasury receipt de-dup regression',
        'actor_user_id' => $actorUserId,
    ], [
        [
            'account_code' => 'CASH_ON_HAND',
            'customer_receipt_id' => $receiptId,
            'line_description' => 'Receipt booked into treasury account',
            'debit_amount' => $amount,
            'credit_amount' => 0.00,
        ],
        [
            'account_code' => 'AR_CONTROL',
            'customer_receipt_id' => $receiptId,
            'line_description' => 'Customer receivable reduced by receipt',
            'debit_amount' => 0.00,
            'credit_amount' => $amount,
        ],
    ]);

    $balance = $treasuryRepository->currentBalanceForAccountId($accountId);

    $check(
        'Treasury current balance counts a receipt-linked journal line only once even when both receipt and treasury-account matching paths apply',
        abs($balance - $amount) < 0.01,
        'balance=' . number_format($balance, 2, '.', '')
    );
} catch (Throwable $exception) {
    $check('Treasury receipt balance de-dup regression completed without exception', false, $exception->getMessage());
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
}

echo PHP_EOL;
echo empty($failures)
    ? 'All treasury receipt balance de-dup regression checks passed.' . PHP_EOL
    : 'Failures:' . PHP_EOL . ' - ' . implode(PHP_EOL . ' - ', $failures) . PHP_EOL;

return empty($failures) ? 0 : 1;
