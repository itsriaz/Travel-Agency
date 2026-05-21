<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

require BASE_PATH . '/app/Helpers/functions.php';
require BASE_PATH . '/app/Core/bootstrap.php';

$app = \App\Core\App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');

$options = getopt('', ['apply', 'help']);
if (isset($options['help'])) {
    echo 'Usage: php scripts/reconcile_supplier_payment_totals.php [--apply]' . PHP_EOL;
    echo 'Without --apply, this command only prints rows that would be repaired.' . PHP_EOL;
    exit(0);
}

$apply = array_key_exists('apply', $options);

$rows = $db->query(
    'SELECT
        sp.id,
        sp.payment_no,
        sp.booking_reference,
        sp.currency,
        sp.paid_amount,
        sp.allocated_amount AS stored_allocated_amount,
        sp.unallocated_amount AS stored_unallocated_amount,
        sp.status AS stored_status,
        COALESCE(SUM(spa.allocated_amount), 0) AS allocation_rows_total
     FROM supplier_payments sp
     LEFT JOIN supplier_payment_allocations spa ON spa.supplier_payment_id = sp.id
     WHERE sp.status <> "void"
     GROUP BY sp.id
     HAVING ABS(sp.allocated_amount - allocation_rows_total) > 0.005
         OR ABS(sp.unallocated_amount - GREATEST(0, sp.paid_amount - allocation_rows_total)) > 0.005
         OR sp.status <> CASE
             WHEN GREATEST(0, sp.paid_amount - allocation_rows_total) <= 0.005 THEN "fully_allocated"
             WHEN allocation_rows_total > 0 THEN "partially_allocated"
             ELSE "paid"
         END
     ORDER BY sp.id'
)->fetchAll();

if ($rows === []) {
    echo 'Supplier payment totals are already consistent.' . PHP_EOL;
    exit(0);
}

echo ($apply ? 'Applying' : 'Dry run') . ' supplier payment total reconciliation.' . PHP_EOL;

$repairRows = [];
foreach ($rows as $row) {
    $paidAmount = round((float) $row['paid_amount'], 2);
    $allocatedAmount = round((float) $row['allocation_rows_total'], 2);
    $unallocatedAmount = round(max(0, $paidAmount - $allocatedAmount), 2);
    $status = $unallocatedAmount <= 0.005
        ? 'fully_allocated'
        : ($allocatedAmount > 0 ? 'partially_allocated' : 'paid');

    $repairRows[] = [
        'id' => (int) $row['id'],
        'payment_no' => (string) $row['payment_no'],
        'booking_reference' => (string) $row['booking_reference'],
        'currency' => (string) $row['currency'],
        'stored_allocated_amount' => (string) $row['stored_allocated_amount'],
        'new_allocated_amount' => number_format($allocatedAmount, 2, '.', ''),
        'stored_unallocated_amount' => (string) $row['stored_unallocated_amount'],
        'new_unallocated_amount' => number_format($unallocatedAmount, 2, '.', ''),
        'stored_status' => (string) $row['stored_status'],
        'new_status' => $status,
    ];
}

foreach ($repairRows as $repairRow) {
    echo json_encode($repairRow, JSON_UNESCAPED_SLASHES) . PHP_EOL;
}

if (! $apply) {
    echo PHP_EOL . 'No changes applied. Re-run with --apply after confirming backup exists.' . PHP_EOL;
    exit(0);
}

$db->beginTransaction();
try {
    $statement = $db->prepare(
        'UPDATE supplier_payments
         SET allocated_amount = :allocated_amount,
             unallocated_amount = :unallocated_amount,
             status = :status
         WHERE id = :id
           AND status <> "void"'
    );

    foreach ($repairRows as $repairRow) {
        $statement->execute([
            'id' => $repairRow['id'],
            'allocated_amount' => $repairRow['new_allocated_amount'],
            'unallocated_amount' => $repairRow['new_unallocated_amount'],
            'status' => $repairRow['new_status'],
        ]);
    }

    $db->commit();
} catch (Throwable $exception) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }

    fwrite(STDERR, 'Reconciliation failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}

echo PHP_EOL . 'Supplier payment totals reconciled: ' . count($repairRows) . PHP_EOL;
