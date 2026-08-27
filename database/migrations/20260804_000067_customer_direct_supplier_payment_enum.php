<?php

declare(strict_types=1);

return [
    'up' => [
        static function (PDO $db): void {
            $db->exec(
                "ALTER TABLE customer_receipts
                 MODIFY COLUMN payment_method
                 ENUM('cash', 'bank_transfer', 'debit_card', 'credit_card', 'customer_paid_supplier') NOT NULL"
            );

            $db->exec(
                "ALTER TABLE supplier_payments
                 MODIFY COLUMN payment_method
                 ENUM('cash', 'bank_transfer', 'debit_card', 'credit_card', 'customer_paid_supplier') NOT NULL"
            );
        },
    ],
    'down' => [
        static function (PDO $db): void {
            // Deliberately retained. Removing this enum value after financial postings exist
            // would either corrupt their audit method or make the rollback destructive.
        },
    ],
];
