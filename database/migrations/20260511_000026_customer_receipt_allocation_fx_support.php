<?php

declare(strict_types=1);

return [
    'up' => [
        static function (\PDO $db): void {
            $columns = [];
            foreach (($db->query('SHOW COLUMNS FROM customer_receipt_allocations')->fetchAll(\PDO::FETCH_ASSOC) ?: []) as $column) {
                $columns[(string) ($column['Field'] ?? '')] = true;
            }

            $addColumn = static function (string $name, string $definition) use ($db, $columns): void {
                if (isset($columns[$name])) {
                    return;
                }

                $db->exec('ALTER TABLE customer_receipt_allocations ADD COLUMN ' . $definition);
            };

            $addColumn('receivable_currency', 'receivable_currency CHAR(3) NULL AFTER allocated_amount');
            $addColumn('receivable_amount_allocated', 'receivable_amount_allocated DECIMAL(18,2) NULL AFTER receivable_currency');
            $addColumn('payment_currency', 'payment_currency CHAR(3) NULL AFTER receivable_amount_allocated');
            $addColumn('payment_amount_consumed', 'payment_amount_consumed DECIMAL(18,2) NULL AFTER payment_currency');
            $addColumn('rate_from_currency', 'rate_from_currency CHAR(3) NULL AFTER exchange_rate_used');
            $addColumn('rate_to_currency', 'rate_to_currency CHAR(3) NULL AFTER rate_from_currency');
            $addColumn('exchange_rate', 'exchange_rate DECIMAL(18,8) NULL AFTER rate_to_currency');
            $addColumn('exchange_rate_effective_date', 'exchange_rate_effective_date DATE NULL AFTER exchange_rate');

            $indexes = [];
            foreach (($db->query('SHOW INDEX FROM customer_receipt_allocations')->fetchAll(\PDO::FETCH_ASSOC) ?: []) as $indexRow) {
                $indexes[(string) ($indexRow['Key_name'] ?? '')] = true;
            }

            if (! isset($indexes['idx_customer_receipt_allocations_receivable_currency'])) {
                $db->exec('CREATE INDEX idx_customer_receipt_allocations_receivable_currency ON customer_receipt_allocations (receivable_currency)');
            }
            if (! isset($indexes['idx_customer_receipt_allocations_payment_currency'])) {
                $db->exec('CREATE INDEX idx_customer_receipt_allocations_payment_currency ON customer_receipt_allocations (payment_currency)');
            }
            if (! isset($indexes['idx_customer_receipt_allocations_rate_effective_date'])) {
                $db->exec('CREATE INDEX idx_customer_receipt_allocations_rate_effective_date ON customer_receipt_allocations (exchange_rate_effective_date)');
            }

            $db->exec(
                'UPDATE customer_receipt_allocations a
                 INNER JOIN customer_receipts r ON r.id = a.customer_receipt_id
                 INNER JOIN customer_receivable_items i ON i.id = a.customer_receivable_item_id
                 SET
                    a.receivable_currency = COALESCE(a.receivable_currency, i.currency),
                    a.receivable_amount_allocated = COALESCE(a.receivable_amount_allocated, a.allocated_amount),
                    a.payment_currency = COALESCE(a.payment_currency, r.currency),
                    a.payment_amount_consumed = CASE
                        WHEN a.payment_amount_consumed IS NOT NULL AND a.payment_amount_consumed > 0 THEN a.payment_amount_consumed
                        WHEN r.currency = i.currency THEN a.allocated_amount
                        WHEN a.exchange_rate_used IS NOT NULL AND a.exchange_rate_used > 0 THEN ROUND(a.allocated_amount / a.exchange_rate_used, 2)
                        ELSE NULL
                    END,
                    a.rate_from_currency = CASE
                        WHEN a.rate_from_currency IS NOT NULL AND a.rate_from_currency <> "" THEN a.rate_from_currency
                        WHEN r.currency = i.currency THEN i.currency
                        WHEN a.exchange_rate_used IS NOT NULL AND a.exchange_rate_used > 0 THEN i.currency
                        ELSE NULL
                    END,
                    a.rate_to_currency = CASE
                        WHEN a.rate_to_currency IS NOT NULL AND a.rate_to_currency <> "" THEN a.rate_to_currency
                        WHEN r.currency = i.currency THEN r.currency
                        WHEN a.exchange_rate_used IS NOT NULL AND a.exchange_rate_used > 0 THEN r.currency
                        ELSE NULL
                    END,
                    a.exchange_rate = CASE
                        WHEN a.exchange_rate IS NOT NULL AND a.exchange_rate > 0 THEN a.exchange_rate
                        WHEN r.currency = i.currency THEN 1.00000000
                        WHEN a.exchange_rate_used IS NOT NULL AND a.exchange_rate_used > 0 THEN ROUND(1 / a.exchange_rate_used, 8)
                        ELSE NULL
                    END,
                    a.exchange_rate_effective_date = COALESCE(a.exchange_rate_effective_date, r.receipt_date),
                    a.allocation_note = CASE
                        WHEN r.currency <> i.currency
                             AND (a.exchange_rate_used IS NULL OR a.exchange_rate_used <= 0)
                             AND (a.allocation_note IS NULL OR a.allocation_note NOT LIKE "%FX BACKFILL REVIEW%")
                        THEN LEFT(CONCAT(
                            TRIM(COALESCE(a.allocation_note, "")),
                            CASE WHEN TRIM(COALESCE(a.allocation_note, "")) = "" THEN "" ELSE " " END,
                            "[FX BACKFILL REVIEW]"
                        ), 190)
                        ELSE a.allocation_note
                    END'
            );
        },
    ],
    'down' => [
        static function (\PDO $db): void {
            $indexes = [];
            foreach (($db->query('SHOW INDEX FROM customer_receipt_allocations')->fetchAll(\PDO::FETCH_ASSOC) ?: []) as $indexRow) {
                $indexes[(string) ($indexRow['Key_name'] ?? '')] = true;
            }

            foreach ([
                'idx_customer_receipt_allocations_receivable_currency',
                'idx_customer_receipt_allocations_payment_currency',
                'idx_customer_receipt_allocations_rate_effective_date',
            ] as $indexName) {
                if (isset($indexes[$indexName])) {
                    $db->exec('ALTER TABLE customer_receipt_allocations DROP INDEX ' . $indexName);
                }
            }

            $columns = [];
            foreach (($db->query('SHOW COLUMNS FROM customer_receipt_allocations')->fetchAll(\PDO::FETCH_ASSOC) ?: []) as $column) {
                $columns[(string) ($column['Field'] ?? '')] = true;
            }

            foreach ([
                'exchange_rate_effective_date',
                'exchange_rate',
                'rate_to_currency',
                'rate_from_currency',
                'payment_amount_consumed',
                'payment_currency',
                'receivable_amount_allocated',
                'receivable_currency',
            ] as $columnName) {
                if (isset($columns[$columnName])) {
                    $db->exec('ALTER TABLE customer_receipt_allocations DROP COLUMN ' . $columnName);
                }
            }
        },
    ],
];
