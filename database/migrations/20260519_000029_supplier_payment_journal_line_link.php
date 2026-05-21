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

            if (! $columnExists('journal_entry_lines', 'supplier_payment_id')) {
                $db->exec('ALTER TABLE journal_entry_lines ADD COLUMN supplier_payment_id BIGINT UNSIGNED NULL AFTER supplier_obligation_id');
            }

            if (! $indexExists('journal_entry_lines', 'idx_journal_entry_lines_supplier_payment')) {
                $db->exec('CREATE INDEX idx_journal_entry_lines_supplier_payment ON journal_entry_lines (supplier_payment_id)');
            }

            if (! $foreignKeyExists('journal_entry_lines', 'fk_journal_entry_lines_supplier_payment')) {
                $db->exec(
                    'ALTER TABLE journal_entry_lines
                     ADD CONSTRAINT fk_journal_entry_lines_supplier_payment
                     FOREIGN KEY (supplier_payment_id) REFERENCES supplier_payments (id) ON DELETE SET NULL'
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

            if ($foreignKeyExists('journal_entry_lines', 'fk_journal_entry_lines_supplier_payment')) {
                $db->exec('ALTER TABLE journal_entry_lines DROP FOREIGN KEY fk_journal_entry_lines_supplier_payment');
            }

            if ($indexExists('journal_entry_lines', 'idx_journal_entry_lines_supplier_payment')) {
                $db->exec('DROP INDEX idx_journal_entry_lines_supplier_payment ON journal_entry_lines');
            }

            if ($columnExists('journal_entry_lines', 'supplier_payment_id')) {
                $db->exec('ALTER TABLE journal_entry_lines DROP COLUMN supplier_payment_id');
            }
        },
    ],
];
