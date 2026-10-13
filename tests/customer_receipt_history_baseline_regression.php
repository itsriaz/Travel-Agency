<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/Helpers/functions.php';
require BASE_PATH . '/app/Core/bootstrap.php';

$db = \App\Core\App::bootstrap(BASE_PATH)->get('db');

try {
    $missing = (int) $db->query(
        'SELECT COUNT(*) FROM customer_receipt_allocations a
         WHERE NOT EXISTS (
             SELECT 1 FROM customer_receipt_allocation_movements m
             WHERE m.allocation_id = a.id
         )'
    )->fetchColumn();
    $duplicateBaselines = (int) $db->query(
        'SELECT COUNT(*) FROM (
             SELECT allocation_id FROM customer_receipt_allocation_movements
             WHERE action = "baseline" GROUP BY allocation_id HAVING COUNT(*) > 1
         ) duplicates'
    )->fetchColumn();
    $unmarkedOrphans = (int) $db->query(
        'SELECT COUNT(*) FROM customer_receipt_allocations a
         LEFT JOIN customer_receipts r ON r.id = a.customer_receipt_id
         LEFT JOIN customer_receivable_items i ON i.id = a.customer_receivable_item_id
         LEFT JOIN customer_receipt_allocation_movements m
           ON m.allocation_id = a.id AND m.action = "baseline"
         WHERE (r.id IS NULL OR i.id IS NULL)
           AND (m.id IS NULL OR m.reason NOT LIKE "%missing receipt or receivable%")'
    )->fetchColumn();

    if ($missing !== 0 || $duplicateBaselines !== 0 || $unmarkedOrphans !== 0) {
        throw new RuntimeException(sprintf(
            'Missing movements: %d; duplicate baselines: %d; unmarked orphan allocations: %d',
            $missing,
            $duplicateBaselines,
            $unmarkedOrphans
        ));
    }

    echo '[PASS] Every allocation has history; orphan baselines are marked and baselines are unique.' . PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, '[FAIL] ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
