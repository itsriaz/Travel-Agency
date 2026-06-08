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

            $foreignKeyExists = static function (string $table, string $constraintName) use ($db): bool {
                $statement = $db->prepare(
                    'SELECT 1
                     FROM information_schema.table_constraints
                     WHERE constraint_schema = DATABASE()
                       AND table_name = :table_name
                       AND constraint_name = :constraint_name
                       AND constraint_type = "FOREIGN KEY"
                     LIMIT 1'
                );
                $statement->execute([
                    'table_name' => $table,
                    'constraint_name' => $constraintName,
                ]);

                return $statement->fetchColumn() !== false;
            };

            if (! $columnExists('supplier_payments', 'treasury_account_id')) {
                $db->exec('ALTER TABLE supplier_payments ADD COLUMN treasury_account_id BIGINT UNSIGNED NULL AFTER payment_method');
            }

            if (! $indexExists('supplier_payments', 'idx_supplier_payments_treasury_account')) {
                $db->exec('CREATE INDEX idx_supplier_payments_treasury_account ON supplier_payments (treasury_account_id)');
            }

            if (! $foreignKeyExists('supplier_payments', 'fk_supplier_payments_treasury_account')) {
                $db->exec(
                    'ALTER TABLE supplier_payments
                     ADD CONSTRAINT fk_supplier_payments_treasury_account
                     FOREIGN KEY (treasury_account_id) REFERENCES treasury_accounts (id) ON DELETE SET NULL'
                );
            }
        },
    ],
    'down' => [
        static function (PDO $db): void {
            $foreignKeyExists = static function (string $table, string $constraintName) use ($db): bool {
                $statement = $db->prepare(
                    'SELECT 1
                     FROM information_schema.table_constraints
                     WHERE constraint_schema = DATABASE()
                       AND table_name = :table_name
                       AND constraint_name = :constraint_name
                       AND constraint_type = "FOREIGN KEY"
                     LIMIT 1'
                );
                $statement->execute([
                    'table_name' => $table,
                    'constraint_name' => $constraintName,
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

            if ($foreignKeyExists('supplier_payments', 'fk_supplier_payments_treasury_account')) {
                $db->exec('ALTER TABLE supplier_payments DROP FOREIGN KEY fk_supplier_payments_treasury_account');
            }

            if ($indexExists('supplier_payments', 'idx_supplier_payments_treasury_account')) {
                $db->exec('DROP INDEX idx_supplier_payments_treasury_account ON supplier_payments');
            }

            if ($columnExists('supplier_payments', 'treasury_account_id')) {
                $db->exec('ALTER TABLE supplier_payments DROP COLUMN treasury_account_id');
            }
        },
    ],
];
