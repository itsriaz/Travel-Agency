<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

require BASE_PATH . '/app/Helpers/functions.php';
require BASE_PATH . '/app/Core/bootstrap.php';

$app = \App\Core\App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');

$options = getopt('', ['apply']);
$apply = array_key_exists('apply', $options);

$treasuryRepository = new \App\Repositories\TreasuryRepository($app);

$statement = $db->query(
    'SELECT
        id,
        receipt_no,
        booking_reference,
        branch_id,
        currency,
        payment_method,
        receipt_date,
        received_amount
     FROM customer_receipts
     WHERE status <> "void"
       AND payment_method IN ("cash", "bank_transfer")
       AND treasury_account_id IS NULL
     ORDER BY id ASC'
);

$receipts = $statement !== false ? ($statement->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];

echo 'Customer receipt treasury-link backfill' . PHP_EOL;
echo 'Mode: ' . ($apply ? 'APPLY' : 'DRY RUN') . PHP_EOL;
echo 'Started: ' . date(DATE_ATOM) . PHP_EOL . PHP_EOL;

if ($receipts === []) {
    echo 'No non-void cash/bank-transfer receipts are missing treasury links.' . PHP_EOL;
    exit(0);
}

$updateStatement = $db->prepare(
    'UPDATE customer_receipts
     SET treasury_account_id = :treasury_account_id
     WHERE id = :id
       AND treasury_account_id IS NULL'
);

$updated = [];
$skipped = [];

foreach ($receipts as $receipt) {
    $receiptId = (int) ($receipt['id'] ?? 0);
    $branchId = (int) ($receipt['branch_id'] ?? 0);
    $currency = strtoupper(trim((string) ($receipt['currency'] ?? 'PKR')));
    $paymentMethod = trim((string) ($receipt['payment_method'] ?? ''));

    $eligible = $treasuryRepository->eligiblePaymentTreasuryAccounts($branchId, $currency, $paymentMethod);
    $default = $treasuryRepository->defaultTreasuryAccountForPayment($branchId, $currency, $paymentMethod);

    if ($default !== null) {
        $selectedAccount = $default;
        $reason = ((int) ($selectedAccount['is_default'] ?? 0) === 1)
            ? 'default eligible treasury account'
            : 'single eligible treasury account';
    } else {
        $selectedAccount = null;
        if ($eligible === []) {
            $reason = 'no eligible treasury account configured';
        } elseif (count($eligible) > 1) {
            $eligibleLabels = array_map(
                static fn (array $row): string => (string) ($row['account_name'] ?? ('#' . ($row['id'] ?? '?'))),
                $eligible
            );
            $reason = 'multiple eligible treasury accounts: ' . implode(', ', $eligibleLabels);
        } else {
            $reason = 'eligible account resolution returned no default';
        }
    }

    if ($selectedAccount === null) {
        $skipped[] = [
            'receipt_no' => (string) ($receipt['receipt_no'] ?? ''),
            'payment_method' => $paymentMethod,
            'currency' => $currency,
            'reason' => $reason,
        ];
        continue;
    }

    if ($apply) {
        $updateStatement->execute([
            'treasury_account_id' => (int) ($selectedAccount['id'] ?? 0),
            'id' => $receiptId,
        ]);
    }

    $updated[] = [
        'receipt_no' => (string) ($receipt['receipt_no'] ?? ''),
        'payment_method' => $paymentMethod,
        'currency' => $currency,
        'treasury_account_id' => (int) ($selectedAccount['id'] ?? 0),
        'treasury_account_name' => (string) ($selectedAccount['account_name'] ?? ''),
        'reason' => $reason,
    ];
}

echo 'Updated/ready to update: ' . count($updated) . PHP_EOL;
foreach ($updated as $row) {
    echo ' - ' . $row['receipt_no']
        . ' -> #' . $row['treasury_account_id']
        . ' ' . $row['treasury_account_name']
        . ' (' . $row['reason'] . ')' . PHP_EOL;
}

echo PHP_EOL . 'Skipped: ' . count($skipped) . PHP_EOL;
foreach ($skipped as $row) {
    echo ' - ' . $row['receipt_no']
        . ' [' . $row['payment_method'] . ' ' . $row['currency'] . ']'
        . ' -> ' . $row['reason'] . PHP_EOL;
}

if (! $apply) {
    echo PHP_EOL . 'Dry run only. Re-run with --apply to backfill safe matches.' . PHP_EOL;
}
