<?php

declare(strict_types=1);

return [
    'up' => [
        static function (PDO $db): void {
            $columnExists = static function (string $table, string $column) use ($db): bool {
                $statement = $db->prepare(
                    'SELECT 1
                     FROM information_schema.columns
                     WHERE table_schema = DATABASE()
                       AND table_name = :table_name
                       AND column_name = :column_name
                     LIMIT 1'
                );
                $statement->execute([
                    'table_name' => $table,
                    'column_name' => $column,
                ]);

                return $statement->fetchColumn() !== false;
            };

            $indexExists = static function (string $table, string $indexName) use ($db): bool {
                $statement = $db->prepare(
                    'SELECT 1
                     FROM information_schema.statistics
                     WHERE table_schema = DATABASE()
                       AND table_name = :table_name
                       AND index_name = :index_name
                     LIMIT 1'
                );
                $statement->execute([
                    'table_name' => $table,
                    'index_name' => $indexName,
                ]);

                return $statement->fetchColumn() !== false;
            };

            if (! $columnExists('supplier_payments', 'payment_scope')) {
                $db->exec('ALTER TABLE supplier_payments ADD COLUMN payment_scope ENUM("booking", "global") NOT NULL DEFAULT "booking" AFTER booking_reference');
            }

            if (! $indexExists('supplier_payments', 'idx_supplier_payments_scope')) {
                $db->exec('CREATE INDEX idx_supplier_payments_scope ON supplier_payments (payment_scope)');
            }
        },
    ],
    'down' => [
        static function (PDO $db): void {
            $columnExists = static function (string $table, string $column) use ($db): bool {
                $statement = $db->prepare(
                    'SELECT 1
                     FROM information_schema.columns
                     WHERE table_schema = DATABASE()
                       AND table_name = :table_name
                       AND column_name = :column_name
                     LIMIT 1'
                );
                $statement->execute([
                    'table_name' => $table,
                    'column_name' => $column,
                ]);

                return $statement->fetchColumn() !== false;
            };

            $indexExists = static function (string $table, string $indexName) use ($db): bool {
                $statement = $db->prepare(
                    'SELECT 1
                     FROM information_schema.statistics
                     WHERE table_schema = DATABASE()
                       AND table_name = :table_name
                       AND index_name = :index_name
                     LIMIT 1'
                );
                $statement->execute([
                    'table_name' => $table,
                    'index_name' => $indexName,
                ]);

                return $statement->fetchColumn() !== false;
            };

            if ($indexExists('supplier_payments', 'idx_supplier_payments_scope')) {
                $db->exec('DROP INDEX idx_supplier_payments_scope ON supplier_payments');
            }

            if ($columnExists('supplier_payments', 'payment_scope')) {
                $db->exec('ALTER TABLE supplier_payments DROP COLUMN payment_scope');
            }
        },
    ],
];
