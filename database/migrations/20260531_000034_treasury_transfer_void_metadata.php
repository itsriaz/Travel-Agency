<?php

declare(strict_types=1);

return [
    'up' => [
        static function (\PDO $db): void {
            $columnsFor = static function (string $table) use ($db): array {
                $columns = [];
                foreach (($db->query('SHOW COLUMNS FROM ' . $table)->fetchAll(\PDO::FETCH_ASSOC) ?: []) as $column) {
                    $columns[(string) ($column['Field'] ?? '')] = true;
                }

                return $columns;
            };

            $indexesFor = static function (string $table) use ($db): array {
                $indexes = [];
                foreach (($db->query('SHOW INDEX FROM ' . $table)->fetchAll(\PDO::FETCH_ASSOC) ?: []) as $indexRow) {
                    $indexes[(string) ($indexRow['Key_name'] ?? '')] = true;
                }

                return $indexes;
            };

            $foreignKeysFor = static function (string $table) use ($db): array {
                $foreignKeys = [];
                $statement = $db->prepare(
                    'SELECT CONSTRAINT_NAME
                     FROM information_schema.TABLE_CONSTRAINTS
                     WHERE TABLE_SCHEMA = DATABASE()
                       AND TABLE_NAME = :table_name
                       AND CONSTRAINT_TYPE = "FOREIGN KEY"'
                );
                $statement->execute(['table_name' => $table]);

                foreach (($statement->fetchAll(\PDO::FETCH_ASSOC) ?: []) as $row) {
                    $foreignKeys[(string) ($row['CONSTRAINT_NAME'] ?? '')] = true;
                }

                return $foreignKeys;
            };

            $columns = $columnsFor('treasury_transactions');
            if (! isset($columns['reversal_journal_entry_id'])) {
                $db->exec('ALTER TABLE treasury_transactions ADD COLUMN reversal_journal_entry_id BIGINT UNSIGNED NULL AFTER void_reason');
            }

            $indexes = $indexesFor('treasury_transactions');
            if (! isset($indexes['idx_treasury_transactions_reversal_journal'])) {
                $db->exec('CREATE INDEX idx_treasury_transactions_reversal_journal ON treasury_transactions (reversal_journal_entry_id)');
            }

            $foreignKeys = $foreignKeysFor('treasury_transactions');
            if (! isset($foreignKeys['fk_treasury_transactions_reversal_journal'])) {
                $db->exec(
                    'ALTER TABLE treasury_transactions
                     ADD CONSTRAINT fk_treasury_transactions_reversal_journal
                     FOREIGN KEY (reversal_journal_entry_id) REFERENCES journal_entries(id) ON DELETE SET NULL'
                );
            }
        },
    ],
    'down' => [
        static function (\PDO $db): void {
            $indexes = [];
            foreach (($db->query('SHOW INDEX FROM treasury_transactions')->fetchAll(\PDO::FETCH_ASSOC) ?: []) as $indexRow) {
                $indexes[(string) ($indexRow['Key_name'] ?? '')] = true;
            }

            $foreignKeys = [];
            $statement = $db->prepare(
                'SELECT CONSTRAINT_NAME
                 FROM information_schema.TABLE_CONSTRAINTS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = "treasury_transactions"
                   AND CONSTRAINT_TYPE = "FOREIGN KEY"'
            );
            $statement->execute();
            foreach (($statement->fetchAll(\PDO::FETCH_ASSOC) ?: []) as $row) {
                $foreignKeys[(string) ($row['CONSTRAINT_NAME'] ?? '')] = true;
            }

            if (isset($foreignKeys['fk_treasury_transactions_reversal_journal'])) {
                $db->exec('ALTER TABLE treasury_transactions DROP FOREIGN KEY fk_treasury_transactions_reversal_journal');
            }

            if (isset($indexes['idx_treasury_transactions_reversal_journal'])) {
                $db->exec('ALTER TABLE treasury_transactions DROP INDEX idx_treasury_transactions_reversal_journal');
            }

            $columns = [];
            foreach (($db->query('SHOW COLUMNS FROM treasury_transactions')->fetchAll(\PDO::FETCH_ASSOC) ?: []) as $column) {
                $columns[(string) ($column['Field'] ?? '')] = true;
            }

            if (isset($columns['reversal_journal_entry_id'])) {
                $db->exec('ALTER TABLE treasury_transactions DROP COLUMN reversal_journal_entry_id');
            }
        },
    ],
];
