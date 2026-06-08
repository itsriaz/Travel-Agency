<?php

declare(strict_types=1);

return [
    'up' => [
        static function (\PDO $db): void {
            $columns = [];
            foreach (($db->query('SHOW COLUMNS FROM customer_receipts')->fetchAll(\PDO::FETCH_ASSOC) ?: []) as $column) {
                $columns[(string) ($column['Field'] ?? '')] = true;
            }

            if (! isset($columns['tendered_amount'])) {
                $db->exec('ALTER TABLE customer_receipts ADD COLUMN tendered_amount DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER currency');
            }

            if (! isset($columns['returned_amount'])) {
                $db->exec('ALTER TABLE customer_receipts ADD COLUMN returned_amount DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER unallocated_amount');
            }

            $db->exec('UPDATE customer_receipts SET tendered_amount = received_amount WHERE tendered_amount = 0.00');
            $db->exec('UPDATE customer_receipts SET returned_amount = 0.00 WHERE returned_amount IS NULL');
        },
    ],
    'down' => [
        static function (\PDO $db): void {
            $columns = [];
            foreach (($db->query('SHOW COLUMNS FROM customer_receipts')->fetchAll(\PDO::FETCH_ASSOC) ?: []) as $column) {
                $columns[(string) ($column['Field'] ?? '')] = true;
            }

            if (isset($columns['returned_amount'])) {
                $db->exec('ALTER TABLE customer_receipts DROP COLUMN returned_amount');
            }

            if (isset($columns['tendered_amount'])) {
                $db->exec('ALTER TABLE customer_receipts DROP COLUMN tendered_amount');
            }
        },
    ],
];
