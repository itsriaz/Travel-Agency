<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';

use App\Core\App;
use App\Repositories\SupplierRepository;
use App\Services\SupplierSettlementWorkspaceService;

$apply = in_array('--apply', $argv, true);
$app = App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');

echo 'Supplier advance to payable reconciliation' . PHP_EOL;
echo 'Mode: ' . ($apply ? 'APPLY' : 'DRY RUN') . PHP_EOL;

$branchIds = array_values(array_filter(array_map(
    'intval',
    $db->query('SELECT id FROM branches WHERE is_active = 1 ORDER BY id')->fetchAll(PDO::FETCH_COLUMN) ?: []
)));
$actorUserId = (int) $db->query(
    'SELECT u.id
     FROM users u
     INNER JOIN roles r ON r.id = u.role_id
     WHERE u.is_active = 1
       AND r.code IN ("super_admin", "branch_admin")
     ORDER BY CASE WHEN r.code = "super_admin" THEN 0 ELSE 1 END, u.id
     LIMIT 1'
)->fetchColumn();

if ($branchIds === []) {
    fwrite(STDERR, 'No active branches were found.' . PHP_EOL);
    exit(1);
}
if ($actorUserId <= 0) {
    fwrite(STDERR, 'No active financial administrator was found.' . PHP_EOL);
    exit(1);
}

$repository = new SupplierRepository($app);
$db->beginTransaction();
try {
    $obligations = $repository->openObligationsForAdvanceReconciliation($branchIds);
    $groups = [];
    foreach ($obligations as $obligation) {
        $supplierId = (int) ($obligation['supplier_id'] ?? 0);
        $currency = strtoupper((string) ($obligation['currency'] ?? ''));
        $key = $supplierId . '|' . $currency;
        if (! isset($groups[$key])) {
            $advanceRows = $repository->availableAdvanceRowsForSupplier($supplierId, $currency);
            $groups[$key] = [
                'supplier_id' => $supplierId,
                'supplier_name' => (string) ($obligation['supplier_name'] ?? 'Supplier'),
                'currency' => $currency,
                'advance' => round(array_reduce(
                    $advanceRows,
                    static fn (float $total, array $row): float => $total + max((float) ($row['available_amount'] ?? 0), 0),
                    0.0
                ), 2),
                'payable' => 0.0,
                'bookings' => [],
            ];
        }
        $groups[$key]['payable'] = round(
            (float) $groups[$key]['payable'] + max((float) ($obligation['net_payable_amount'] ?? 0), 0),
            2
        );
        $groups[$key]['bookings'][(string) ($obligation['booking_reference'] ?? '')] = true;
    }

    $plannedTotal = 0.0;
    $plannedGroups = 0;
    foreach ($groups as $group) {
        $planned = round(min((float) $group['advance'], (float) $group['payable']), 2);
        if ($planned <= 0.005) {
            continue;
        }
        $plannedTotal = round($plannedTotal + $planned, 2);
        $plannedGroups++;
        echo sprintf(
            '[READY] %s: apply %s %s against %s payable booking(s); remaining advance %s.' . PHP_EOL,
            (string) $group['supplier_name'],
            (string) $group['currency'],
            number_format($planned, 2),
            count(array_filter(array_keys((array) $group['bookings']))),
            number_format(max((float) $group['advance'] - $planned, 0), 2)
        );
    }

    if ($db->inTransaction()) {
        $db->rollBack();
    }

    if ($plannedGroups === 0) {
        echo 'No available same-currency supplier advance needs reconciliation.' . PHP_EOL;
        exit(0);
    }

    echo sprintf('Planned total: %s across %d supplier/currency group(s).' . PHP_EOL, number_format($plannedTotal, 2), $plannedGroups);
    if (! $apply) {
        echo 'Dry run complete. No data changed. Take a database backup, then rerun with --apply.' . PHP_EOL;
        exit(0);
    }

    $result = (new SupplierSettlementWorkspaceService($app))->applyAvailableSupplierAdvancesToOpenPayables(
        $branchIds,
        $actorUserId
    );
    echo sprintf(
        'Applied: %s through %d payable reconciliation(s).' . PHP_EOL,
        number_format((float) ($result['applied_amount'] ?? 0), 2),
        (int) ($result['application_count'] ?? 0)
    );
    echo 'Reconciliation complete. No new cash or supplier payment was recorded.' . PHP_EOL;
} catch (Throwable $exception) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, 'Reconciliation failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}

