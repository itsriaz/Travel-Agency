<?php

declare(strict_types=1);

return [
    'up' => [
        static function (PDO $db): void {
            $db->exec(
                "INSERT INTO payment_methods (code, name, ledger_target, charges_target, sort_order, is_system, is_active)
                 VALUES ('customer_paid_supplier', 'Customer Paid Supplier', 'Supplier Direct Clearing', NULL, 25, 1, 1)
                 ON DUPLICATE KEY UPDATE
                    name = VALUES(name),
                    ledger_target = VALUES(ledger_target),
                    charges_target = VALUES(charges_target),
                    sort_order = VALUES(sort_order),
                    is_system = VALUES(is_system),
                    is_active = VALUES(is_active)"
            );
        },
    ],
    'down' => [
        static function (PDO $db): void {
            $statement = $db->prepare(
                "DELETE FROM payment_methods
                 WHERE code = 'customer_paid_supplier'
                   AND NOT EXISTS (
                       SELECT 1 FROM customer_receipts WHERE payment_method = 'customer_paid_supplier' LIMIT 1
                   )
                   AND NOT EXISTS (
                       SELECT 1 FROM supplier_payments WHERE payment_method = 'customer_paid_supplier' LIMIT 1
                   )"
            );
            $statement->execute();
        },
    ],
];
