<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';

use App\Core\App;
use App\Repositories\SupplierRepository;

$apply = in_array('--apply', $argv, true);
$supplierIdFilter = 0;
$supplierNameFilter = '';

foreach ($argv as $argument) {
    if (str_starts_with($argument, '--supplier-id=')) {
        $supplierIdFilter = max(0, (int) substr($argument, 14));
        continue;
    }
    if (str_starts_with($argument, '--supplier-name=')) {
        $supplierNameFilter = trim((string) substr($argument, 16));
    }
}

$app = App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');
$repository = new SupplierRepository($app);

$branchIds = array_values(array_filter(array_map(
    'intval',
    $db->query('SELECT id FROM branches ORDER BY id')->fetchAll(PDO::FETCH_COLUMN) ?: []
)));

$supplier = null;
if ($supplierIdFilter > 0) {
    $supplier = $repository->findSupplierById($supplierIdFilter);
} elseif ($supplierNameFilter !== '') {
    $supplier = $repository->findAccessibleSupplierByName($supplierNameFilter, $branchIds);
    $supplierIdFilter = (int) ($supplier['id'] ?? 0);
}

if ($supplier === null || $supplierIdFilter <= 0) {
    fwrite(STDERR, "Please provide a valid supplier filter.\n");
    exit(1);
}

echo 'Normalize supplier obligation statuses' . PHP_EOL;
echo 'Mode: ' . ($apply ? 'APPLY' : 'DRY RUN') . PHP_EOL;
echo 'Supplier: ' . (string) ($supplier['name'] ?? 'Supplier') . ' (#' . $supplierIdFilter . ')' . PHP_EOL;

$sql = "
    SELECT
        o.id,
        o.booking_reference,
        o.service_line_reference,
        o.currency,
        o.gross_amount,
        o.advance_applied_amount,
        o.net_payable_amount,
        o.status,
        COALESCE((
            SELECT SUM(a.allocated_amount)
            FROM supplier_payment_allocations a
            INNER JOIN supplier_payments p ON p.id = a.supplier_payment_id
            WHERE a.supplier_obligation_id = o.id
              AND p.status <> 'void'
        ), 0) AS allocated_total
    FROM supplier_obligations o
    WHERE o.supplier_id = :supplier_id
      AND o.status <> 'cancelled'
    ORDER BY o.currency ASC, o.booking_reference ASC, o.id ASC
";

$statement = $db->prepare($sql);
$statement->execute(['supplier_id' => $supplierIdFilter]);
$rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];

$changes = [];
foreach ($rows as $row) {
    $netPayable = round((float) ($row['net_payable_amount'] ?? 0), 2);
    $advanceApplied = round((float) ($row['advance_applied_amount'] ?? 0), 2);
    $allocated = round((float) ($row['allocated_total'] ?? 0), 2);
    $currentStatus = (string) ($row['status'] ?? '');

    if ($netPayable <= 0.005) {
        $expectedStatus = ($advanceApplied > 0.005 && $allocated <= 0.005) ? 'covered_by_advance' : 'paid';
    } elseif ($advanceApplied > 0.005 || $allocated > 0.005) {
        $expectedStatus = 'partially_covered';
    } else {
        $expectedStatus = 'open';
    }

    if ($expectedStatus === $currentStatus) {
        continue;
    }

    $changes[] = [
        'id' => (int) ($row['id'] ?? 0),
        'booking_reference' => (string) ($row['booking_reference'] ?? ''),
        'currency' => (string) ($row['currency'] ?? ''),
        'gross_amount' => round((float) ($row['gross_amount'] ?? 0), 2),
        'advance_applied_amount' => $advanceApplied,
        'allocated_total' => $allocated,
        'net_payable_amount' => $netPayable,
        'from_status' => $currentStatus,
        'to_status' => $expectedStatus,
    ];
}

if ($changes === []) {
    echo "No status changes are required.\n";
    exit(0);
}

foreach ($changes as $change) {
    echo json_encode($change, JSON_UNESCAPED_SLASHES) . PHP_EOL;
}

echo 'Planned status changes: ' . count($changes) . PHP_EOL;

if (! $apply) {
    echo "Dry run complete. No data changed.\n";
    exit(0);
}

$db->beginTransaction();
try {
    $update = $db->prepare(
        'UPDATE supplier_obligations
         SET status = :status
         WHERE id = :id'
    );
    foreach ($changes as $change) {
        $update->execute([
            'status' => $change['to_status'],
            'id' => $change['id'],
        ]);
    }

    $db->commit();
    echo 'Applied status changes: ' . count($changes) . PHP_EOL;
} catch (Throwable $exception) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, 'Normalization failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
