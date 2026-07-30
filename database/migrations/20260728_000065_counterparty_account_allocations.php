<?php

declare(strict_types=1);

return [
    'up' => [
        'CREATE TABLE IF NOT EXISTS counterparty_offset_account_allocations (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            counterparty_offset_id BIGINT UNSIGNED NOT NULL,
            customer_receivable_item_id BIGINT UNSIGNED NOT NULL,
            allocated_amount DECIMAL(18,2) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_counterparty_offset_account (counterparty_offset_id, customer_receivable_item_id),
            KEY idx_counterparty_offset_account_item (customer_receivable_item_id),
            CONSTRAINT fk_counterparty_offset_account_header FOREIGN KEY (counterparty_offset_id) REFERENCES counterparty_offsets (id) ON DELETE CASCADE,
            CONSTRAINT fk_counterparty_offset_account_item FOREIGN KEY (customer_receivable_item_id) REFERENCES customer_receivable_items (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    ],
    'down' => [
        'DROP TABLE IF EXISTS counterparty_offset_account_allocations',
    ],
];
