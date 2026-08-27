<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';

$app = \App\Core\App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');

$reason = 'Reversing temporary opening-balance preserve adjustment so treasury balance follows corrected opening balance.';
$actorUserId = 1;

$branchIds = $db->query('SELECT id FROM branches ORDER BY id ASC')->fetchAll(PDO::FETCH_COLUMN);
$branchIds = array_values(array_map('intval', is_array($branchIds) ? $branchIds : []));

if ($branchIds === []) {
    fwrite(STDERR, "No branches found.\n");
    exit(1);
}

$statement = $db->prepare(
    'SELECT id, branch_id, transaction_type, reference_no, narration, status
     FROM treasury_transactions
     WHERE reference_no LIKE :reference
        OR narration LIKE :narration
     ORDER BY id ASC'
);
$statement->execute([
    'reference' => 'OPENING-CORR-%',
    'narration' => '%Auto adjustment posted to preserve live balance%',
]);
$rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];

if ($rows === []) {
    echo "No opening-balance preserve adjustments found.\n";
    exit(0);
}

$repository = new \App\Repositories\TreasuryRepository($app);
$voided = 0;
$skipped = 0;

foreach ($rows as $row) {
    $transactionId = (int) ($row['id'] ?? 0);
    $status = (string) ($row['status'] ?? '');

    if ($transactionId <= 0) {
        $skipped++;
        continue;
    }

    if ($status !== 'posted') {
        echo "[SKIP] {$transactionId} already {$status}\n";
        $skipped++;
        continue;
    }

    try {
        $repository->voidTransfer($transactionId, $reason, $actorUserId, $branchIds);
        echo "[VOIDED] {$transactionId} {$row['reference_no']}\n";
        $voided++;
    } catch (Throwable $exception) {
        fwrite(STDERR, "[ERROR] {$transactionId}: {$exception->getMessage()}\n");
        exit(1);
    }
}

echo "Completed. Voided: {$voided}. Skipped: {$skipped}.\n";
exit(0);
