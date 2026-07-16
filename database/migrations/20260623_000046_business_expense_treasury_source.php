<?php

declare(strict_types=1);

return [
    'up' => [
        static function (\PDO $db): void {
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

            $db->exec(
                'INSERT INTO chart_of_accounts (code, name, account_type, normal_balance, is_system)
                 VALUES ("OPERATING_EXPENSES", "Operating Expenses", "expense", "debit", 1)
                 ON DUPLICATE KEY UPDATE name = VALUES(name)'
            );

            if (! $columnExists('business_expenses', 'treasury_account_id')) {
                $db->exec(
                    'ALTER TABLE business_expenses
                     ADD COLUMN treasury_account_id BIGINT UNSIGNED NULL AFTER payment_method'
                );
            }

            if (! $columnExists('business_expenses', 'journal_entry_id')) {
                $db->exec(
                    'ALTER TABLE business_expenses
                     ADD COLUMN journal_entry_id BIGINT UNSIGNED NULL AFTER treasury_account_id'
                );
            }

            if (! $indexExists('business_expenses', 'idx_business_expenses_treasury_account')) {
                $db->exec('CREATE INDEX idx_business_expenses_treasury_account ON business_expenses (treasury_account_id)');
            }

            if (! $indexExists('business_expenses', 'idx_business_expenses_journal_entry')) {
                $db->exec('CREATE INDEX idx_business_expenses_journal_entry ON business_expenses (journal_entry_id)');
            }

            if (! $foreignKeyExists('business_expenses', 'fk_business_expenses_treasury_account')) {
                $db->exec(
                    'ALTER TABLE business_expenses
                     ADD CONSTRAINT fk_business_expenses_treasury_account
                     FOREIGN KEY (treasury_account_id) REFERENCES treasury_accounts (id) ON DELETE SET NULL'
                );
            }

            if (! $foreignKeyExists('business_expenses', 'fk_business_expenses_journal_entry')) {
                $db->exec(
                    'ALTER TABLE business_expenses
                     ADD CONSTRAINT fk_business_expenses_journal_entry
                     FOREIGN KEY (journal_entry_id) REFERENCES journal_entries (id) ON DELETE SET NULL'
                );
            }
        },
    ],
    'down' => [
        static function (\PDO $db): void {
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

            if ($foreignKeyExists('business_expenses', 'fk_business_expenses_journal_entry')) {
                $db->exec('ALTER TABLE business_expenses DROP FOREIGN KEY fk_business_expenses_journal_entry');
            }

            if ($foreignKeyExists('business_expenses', 'fk_business_expenses_treasury_account')) {
                $db->exec('ALTER TABLE business_expenses DROP FOREIGN KEY fk_business_expenses_treasury_account');
            }

            if ($indexExists('business_expenses', 'idx_business_expenses_journal_entry')) {
                $db->exec('DROP INDEX idx_business_expenses_journal_entry ON business_expenses');
            }

            if ($indexExists('business_expenses', 'idx_business_expenses_treasury_account')) {
                $db->exec('DROP INDEX idx_business_expenses_treasury_account ON business_expenses');
            }

            if ($columnExists('business_expenses', 'journal_entry_id')) {
                $db->exec('ALTER TABLE business_expenses DROP COLUMN journal_entry_id');
            }

            if ($columnExists('business_expenses', 'treasury_account_id')) {
                $db->exec('ALTER TABLE business_expenses DROP COLUMN treasury_account_id');
            }
        },
    ],
];
