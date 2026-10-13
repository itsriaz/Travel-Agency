<?php

declare(strict_types=1);

return [
    'up' => [
        'CREATE TABLE IF NOT EXISTS customer_receipt_allocation_movements (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            allocation_id BIGINT UNSIGNED NOT NULL,
            customer_receipt_id BIGINT UNSIGNED NOT NULL,
            customer_receivable_item_id BIGINT UNSIGNED NOT NULL,
            action VARCHAR(20) NOT NULL,
            receivable_currency CHAR(3) NOT NULL,
            payment_currency CHAR(3) NOT NULL,
            receivable_amount_before DECIMAL(18,2) NOT NULL,
            receivable_amount_after DECIMAL(18,2) NOT NULL,
            payment_amount_before DECIMAL(18,2) NOT NULL,
            payment_amount_after DECIMAL(18,2) NOT NULL,
            reason VARCHAR(190) NULL,
            actor_user_id INT UNSIGNED NULL,
            recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_allocation_movements_allocation (allocation_id, id),
            KEY idx_allocation_movements_receipt (customer_receipt_id, id),
            KEY idx_allocation_movements_receivable (customer_receivable_item_id, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        'INSERT INTO customer_receipt_allocation_movements (
            allocation_id, customer_receipt_id, customer_receivable_item_id, action,
            receivable_currency, payment_currency, receivable_amount_before, receivable_amount_after,
            payment_amount_before, payment_amount_after, reason, actor_user_id, recorded_at
        ) SELECT
            a.id, a.customer_receipt_id, a.customer_receivable_item_id, "baseline",
            COALESCE(NULLIF(a.receivable_currency, ""), i.currency),
            COALESCE(NULLIF(a.payment_currency, ""), r.currency),
            0, a.receivable_amount_allocated, 0, a.payment_amount_consumed,
            CASE WHEN r.id IS NULL OR i.id IS NULL
                THEN "Legacy allocation with missing receipt or receivable at history activation"
                ELSE "Active allocation at history activation"
            END, a.created_by_user_id, NOW()
          FROM customer_receipt_allocations a
          LEFT JOIN customer_receipts r ON r.id = a.customer_receipt_id
          LEFT JOIN customer_receivable_items i ON i.id = a.customer_receivable_item_id
          WHERE NOT EXISTS (
              SELECT 1 FROM customer_receipt_allocation_movements m WHERE m.allocation_id = a.id
          )',
    ],
    'down' => [],
];
