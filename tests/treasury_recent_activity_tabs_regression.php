<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';

$app = \App\Core\App::bootstrap(BASE_PATH);
$repository = new \App\Repositories\TreasuryRepository($app);
$branches = $repository->branches([1, 2]);
$branchIds = array_map(static fn (array $branch): int => (int) $branch['id'], $branches);

$groups = [
    'direct' => ['adjustment_increase', 'adjustment_decrease'],
    'transfer' => ['cash_deposit_to_bank', 'bank_withdrawal_to_cash', 'bank_to_bank_transfer', 'cash_to_cash_transfer'],
];

foreach ($groups as $group => $allowedTypes) {
    $rows = $repository->recentTransfers($branchIds, 20, $group);
    if (count($rows) > 20) {
        throw new RuntimeException($group . ' history exceeded its own 20-entry limit.');
    }

    foreach ($rows as $index => $row) {
        if (!in_array((string) $row['transaction_type'], $allowedTypes, true)) {
            throw new RuntimeException($group . ' history contains a transaction from the other tab.');
        }

        if ($index > 0) {
            $previous = $rows[$index - 1];
            if ($previous['transaction_date'] < $row['transaction_date']
                || ($previous['transaction_date'] === $row['transaction_date'] && (int) $previous['id'] < (int) $row['id'])) {
                throw new RuntimeException($group . ' history is not ordered by date descending.');
            }
        }
    }

    $latest = $repository->recentTransfers($branchIds, 1, $group);
    if ($latest !== [] && ($rows === [] || (int) $latest[0]['id'] !== (int) $rows[0]['id'])) {
        throw new RuntimeException($group . ' latest entry does not match the first history row.');
    }

    echo '[PASS] ' . $group . ' history: ' . count($rows) . ' correctly filtered, date-descending entries' . PHP_EOL;
}
