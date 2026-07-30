<?php

declare(strict_types=1);

return [
    'up' => [
        static function (PDO $db): void {
            $statement = $db->query(
                "SELECT COUNT(*)
                 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'supplier_payments'
                   AND COLUMN_NAME = 'returned_amount'"
            );
            if ((int) $statement->fetchColumn() === 0) {
                $db->exec(
                    'ALTER TABLE supplier_payments
                     ADD COLUMN returned_amount DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER unallocated_amount'
                );
            }
        },
    ],
    'down' => [],
];

