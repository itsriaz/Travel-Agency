<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';

$app = \App\Core\App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');
$apply = in_array('--apply', $argv, true);

echo 'Legacy linked-party customer allocation conversion' . PHP_EOL;
echo 'Mode: ' . ($apply ? 'APPLY' : 'DRY RUN') . PHP_EOL;

$rows = $db->query(
    'SELECT ra.id AS legacy_allocation_id, ra.counterparty_offset_id, ra.customer_receivable_item_id,
            ra.allocated_amount, o.offset_no, o.branch_id, o.currency, cri.booking_reference,
            cri.due_amount, cri.allocated_amount AS customer_allocated_amount,
            cri.outstanding_amount AS customer_outstanding_amount
     FROM counterparty_offset_receivable_allocations ra
     INNER JOIN counterparty_offsets o ON o.id = ra.counterparty_offset_id AND o.status = "posted"
     INNER JOIN customer_receivable_items cri ON cri.id = ra.customer_receivable_item_id
     ORDER BY o.id, ra.id'
)->fetchAll(PDO::FETCH_ASSOC) ?: [];

if ($rows === []) {
    echo 'No posted legacy customer allocations remain. No data changed.' . PHP_EOL;
    exit(0);
}

$total = 0.0;
foreach ($rows as $row) {
    $amount = round((float) $row['allocated_amount'], 2);
    $total += $amount;
    echo '[READY] ' . $row['offset_no'] . ' / ' . $row['booking_reference'] . ' / '
        . $row['currency'] . ' ' . number_format($amount, 2)
        . ': restore customer outstanding and retain the amount as account-holder settlement capacity used.' . PHP_EOL;
    if ($amount <= 0.005 || (float) $row['customer_allocated_amount'] + 0.005 < $amount) {
        throw new RuntimeException('Legacy allocation ' . $row['legacy_allocation_id'] . ' is not safe to convert. No data changed.');
    }
}
echo '[TOTAL] ' . count($rows) . ' allocation(s), ' . number_format($total, 2) . ' across native currencies.' . PHP_EOL;

if (! $apply) {
    echo 'Dry run complete. No data changed. Re-run with --apply after confirming a database backup.' . PHP_EOL;
    exit(0);
}

$before = [
    'receipts' => (int) $db->query('SELECT COUNT(*) FROM customer_receipts')->fetchColumn(),
    'supplier_payments' => (int) $db->query('SELECT COUNT(*) FROM supplier_payments')->fetchColumn(),
    'treasury' => (int) $db->query('SELECT COUNT(*) FROM treasury_transactions')->fetchColumn(),
    'journals' => (int) $db->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn(),
];

$db->beginTransaction();
try {
    $insert = $db->prepare(
        'INSERT INTO counterparty_offset_account_allocations
            (counterparty_offset_id, customer_receivable_item_id, allocated_amount, created_at)
         SELECT counterparty_offset_id, customer_receivable_item_id, allocated_amount, created_at
         FROM counterparty_offset_receivable_allocations WHERE id = :legacy_id'
    );
    $restore = $db->prepare(
        'UPDATE customer_receivable_items
         SET allocated_amount = GREATEST(0, allocated_amount - :allocated_amount),
             outstanding_amount = LEAST(due_amount, outstanding_amount + :outstanding_amount),
             status = CASE
                WHEN due_amount <= 0.005 THEN "cancelled"
                WHEN allocated_amount - :status_amount <= 0.005 THEN "open"
                ELSE "partially_paid"
             END
         WHERE id = :receivable_id'
    );
    $delete = $db->prepare('DELETE FROM counterparty_offset_receivable_allocations WHERE id = :legacy_id');
    foreach ($rows as $row) {
        $amount = round((float) $row['allocated_amount'], 2);
        $insert->execute(['legacy_id' => (int) $row['legacy_allocation_id']]);
        $restore->execute([
            'allocated_amount' => $amount,
            'outstanding_amount' => $amount,
            'status_amount' => $amount,
            'receivable_id' => (int) $row['customer_receivable_item_id'],
        ]);
        $delete->execute(['legacy_id' => (int) $row['legacy_allocation_id']]);
    }

    $drift = $db->query(
        'SELECT cri.id, cri.booking_reference, cri.due_amount, cri.allocated_amount, cri.outstanding_amount,
                COALESCE(receipts.receipt_total, 0) AS receipt_total
         FROM customer_receivable_items cri
         LEFT JOIN (
            SELECT cra.customer_receivable_item_id, SUM(cra.receivable_amount_allocated) AS receipt_total
            FROM customer_receipt_allocations cra
            INNER JOIN customer_receipts cr ON cr.id = cra.customer_receipt_id AND cr.status <> "void"
            GROUP BY cra.customer_receivable_item_id
         ) receipts ON receipts.customer_receivable_item_id = cri.id
         WHERE ABS(cri.allocated_amount - COALESCE(receipts.receipt_total, 0)) > 0.005
            OR ABS(cri.due_amount - cri.allocated_amount - cri.outstanding_amount) > 0.005
         LIMIT 1'
    )->fetch(PDO::FETCH_ASSOC);
    if (is_array($drift)) {
        throw new RuntimeException('Customer receivable truth failed after conversion: ' . json_encode($drift, JSON_UNESCAPED_SLASHES));
    }
    $remainingLegacy = (int) $db->query(
        'SELECT COUNT(*) FROM counterparty_offset_receivable_allocations ra
         INNER JOIN counterparty_offsets o ON o.id = ra.counterparty_offset_id AND o.status = "posted"'
    )->fetchColumn();
    if ($remainingLegacy !== 0) throw new RuntimeException('A posted legacy customer allocation remains. Conversion rolled back.');

    $after = [
        'receipts' => (int) $db->query('SELECT COUNT(*) FROM customer_receipts')->fetchColumn(),
        'supplier_payments' => (int) $db->query('SELECT COUNT(*) FROM supplier_payments')->fetchColumn(),
        'treasury' => (int) $db->query('SELECT COUNT(*) FROM treasury_transactions')->fetchColumn(),
        'journals' => (int) $db->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn(),
    ];
    if ($before !== $after) throw new RuntimeException('Cash, payment, receipt, or journal row counts changed. Conversion rolled back.');

    \App\Helpers\AuditLog::record($app, 'counterparty.offset.legacy_allocations_converted', [
        'allocation_count' => count($rows),
        'total_native_amount' => round($total, 2),
        'offset_ids' => array_values(array_unique(array_map(static fn (array $row): int => (int) $row['counterparty_offset_id'], $rows))),
    ]);
    $db->commit();
    echo '[PASS] All posted legacy allocations now use account-holder positions.' . PHP_EOL;
    echo '[PASS] Customer invoices were restored without creating receipts or changing treasury.' . PHP_EOL;
} catch (Throwable $exception) {
    if ($db->inTransaction()) $db->rollBack();
    throw $exception;
}
