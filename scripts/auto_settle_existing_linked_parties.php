<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';

$app = \App\Core\App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');
$apply = in_array('--apply', $argv, true);
$actorId = 0;
foreach ($argv as $argument) {
    if (str_starts_with($argument, '--actor-id=')) {
        $actorId = (int) substr($argument, strlen('--actor-id='));
    }
}
if ($actorId <= 0) {
    $actorId = (int) $db->query(
        'SELECT u.id
         FROM users u
         INNER JOIN roles r ON r.id = u.role_id
         WHERE u.is_active = 1 AND r.code IN ("super_admin", "branch_admin")
         ORDER BY (r.code = "super_admin") DESC, u.id ASC
         LIMIT 1'
    )->fetchColumn();
}
if ($actorId <= 0) {
    throw new RuntimeException('No active financial administrator is available for the audit trail.');
}

$branchIds = array_map('intval', $db->query('SELECT id FROM branches WHERE is_active = 1 ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
$positions = (new \App\Repositories\CounterpartyOffsetRepository($app))->positions($branchIds);
$eligible = array_values(array_filter(
    $positions,
    static fn (array $row): bool => (float) ($row['available_offset'] ?? 0) > 0.005
));

echo 'Automatic linked-party settlement catch-up' . PHP_EOL;
echo 'Mode: ' . ($apply ? 'APPLY' : 'DRY RUN') . PHP_EOL;
echo 'Actor user ID: ' . $actorId . PHP_EOL;

if ($eligible === []) {
    echo 'No matching linked customer receivable and supplier payable require adjustment.' . PHP_EOL;
    exit(0);
}

foreach ($eligible as $row) {
    echo sprintf(
        '[READY] %s <-> %s | %s | %s %.2f%s',
        (string) ($row['business_source_name'] ?? 'Account holder'),
        (string) ($row['supplier_name'] ?? 'Supplier'),
        (string) ($row['branch_name'] ?? 'Branch'),
        (string) ($row['currency'] ?? ''),
        (float) ($row['available_offset'] ?? 0),
        PHP_EOL
    );
}

if (! $apply) {
    echo 'Dry run complete. No data changed. Re-run with --apply after reviewing the balances.' . PHP_EOL;
    exit(0);
}

$settlements = (new \App\Services\CounterpartyOffsetService($app))->autoSettleExisting(
    $branchIds,
    date('Y-m-d'),
    $actorId
);
$total = array_reduce(
    $settlements,
    static fn (float $sum, array $row): float => $sum + (float) ($row['amount'] ?? 0),
    0.0
);

foreach ($settlements as $settlement) {
    echo sprintf(
        '[POSTED] %s | %s %.2f%s',
        (string) ($settlement['offset_no'] ?? 'Automatic settlement'),
        (string) ($settlement['currency'] ?? ''),
        (float) ($settlement['amount'] ?? 0),
        PHP_EOL
    );
}
echo sprintf('Complete: %d settlement(s), total nominal amount %.2f.%s', count($settlements), $total, PHP_EOL);

