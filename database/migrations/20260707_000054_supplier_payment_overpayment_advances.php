<?php

declare(strict_types=1);

return [
    'up' => [
        static function (PDO $db): void {
            $columnExists = static function (string $tableName, string $columnName) use ($db): bool {
                $statement = $db->prepare(
                    'SELECT 1
                     FROM information_schema.columns
                     WHERE table_schema = DATABASE()
                       AND table_name = :table_name
                       AND column_name = :column_name
                     LIMIT 1'
                );
                $statement->execute([
                    'table_name' => $tableName,
                    'column_name' => $columnName,
                ]);

                return $statement->fetchColumn() !== false;
            };

            if (! $columnExists('supplier_payments', 'converted_advance_amount')) {
                $db->exec(
                    'ALTER TABLE supplier_payments
                     ADD COLUMN converted_advance_amount DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER unallocated_amount'
                );
            }

            if (! $columnExists('supplier_payments', 'converted_advance_id')) {
                $db->exec(
                    'ALTER TABLE supplier_payments
                     ADD COLUMN converted_advance_id BIGINT UNSIGNED NULL AFTER converted_advance_amount,
                     ADD INDEX idx_supplier_payments_converted_advance_id (converted_advance_id),
                     ADD CONSTRAINT fk_supplier_payments_converted_advance
                        FOREIGN KEY (converted_advance_id) REFERENCES supplier_advances(id) ON DELETE SET NULL'
                );
            }

            if (! $columnExists('supplier_advances', 'source_supplier_payment_id')) {
                $db->exec(
                    'ALTER TABLE supplier_advances
                     ADD COLUMN source_supplier_payment_id BIGINT UNSIGNED NULL AFTER created_by_user_id,
                     ADD INDEX idx_supplier_advances_source_supplier_payment_id (source_supplier_payment_id),
                     ADD CONSTRAINT fk_supplier_advances_source_supplier_payment
                        FOREIGN KEY (source_supplier_payment_id) REFERENCES supplier_payments(id) ON DELETE SET NULL'
                );
            }
        },
    ],
    'down' => [
        static function (PDO $db): void {
            $columnExists = static function (string $tableName, string $columnName) use ($db): bool {
                $statement = $db->prepare(
                    'SELECT 1
                     FROM information_schema.columns
                     WHERE table_schema = DATABASE()
                       AND table_name = :table_name
                       AND column_name = :column_name
                     LIMIT 1'
                );
                $statement->execute([
                    'table_name' => $tableName,
                    'column_name' => $columnName,
                ]);

                return $statement->fetchColumn() !== false;
            };

            if ($columnExists('supplier_advances', 'source_supplier_payment_id')) {
                $db->exec('ALTER TABLE supplier_advances DROP FOREIGN KEY fk_supplier_advances_source_supplier_payment');
                $db->exec('ALTER TABLE supplier_advances DROP INDEX idx_supplier_advances_source_supplier_payment_id');
                $db->exec('ALTER TABLE supplier_advances DROP COLUMN source_supplier_payment_id');
            }

            if ($columnExists('supplier_payments', 'converted_advance_id')) {
                $db->exec('ALTER TABLE supplier_payments DROP FOREIGN KEY fk_supplier_payments_converted_advance');
                $db->exec('ALTER TABLE supplier_payments DROP INDEX idx_supplier_payments_converted_advance_id');
                $db->exec('ALTER TABLE supplier_payments DROP COLUMN converted_advance_id');
            }

            if ($columnExists('supplier_payments', 'converted_advance_amount')) {
                $db->exec('ALTER TABLE supplier_payments DROP COLUMN converted_advance_amount');
            }
        },
    ],
];
