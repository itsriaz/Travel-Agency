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

            if (! isset($columns['source_branch_id'])) {
                $db->exec(
                    'ALTER TABLE treasury_transactions
                     ADD COLUMN source_branch_id INT UNSIGNED NULL AFTER branch_id'
                );
            }

            if (! isset($columns['destination_branch_id'])) {
                $db->exec(
                    'ALTER TABLE treasury_transactions
                     ADD COLUMN destination_branch_id INT UNSIGNED NULL AFTER source_branch_id'
                );
            }

            if (! isset($columns['source_currency'])) {
                $db->exec(
                    'ALTER TABLE treasury_transactions
                     ADD COLUMN source_currency CHAR(3) NULL AFTER currency'
                );
            }

            if (! isset($columns['destination_currency'])) {
                $db->exec(
                    'ALTER TABLE treasury_transactions
                     ADD COLUMN destination_currency CHAR(3) NULL AFTER source_currency'
                );
            }

            if (! isset($columns['source_amount'])) {
                $db->exec(
                    'ALTER TABLE treasury_transactions
                     ADD COLUMN source_amount DECIMAL(18,2) NULL AFTER amount'
                );
            }

            if (! isset($columns['destination_amount'])) {
                $db->exec(
                    'ALTER TABLE treasury_transactions
                     ADD COLUMN destination_amount DECIMAL(18,2) NULL AFTER source_amount'
                );
            }

            if (! isset($columns['exchange_rate'])) {
                $db->exec(
                    'ALTER TABLE treasury_transactions
                     ADD COLUMN exchange_rate DECIMAL(18,8) NULL AFTER destination_amount'
                );
            }

            if (! isset($columns['exchange_rate_effective_date'])) {
                $db->exec(
                    'ALTER TABLE treasury_transactions
                     ADD COLUMN exchange_rate_effective_date DATE NULL AFTER exchange_rate'
                );
            }

            if (! isset($columns['destination_journal_entry_id'])) {
                $db->exec(
                    'ALTER TABLE treasury_transactions
                     ADD COLUMN destination_journal_entry_id BIGINT UNSIGNED NULL AFTER journal_entry_id'
                );
            }

            if (! isset($columns['destination_reversal_journal_entry_id'])) {
                $db->exec(
                    'ALTER TABLE treasury_transactions
                     ADD COLUMN destination_reversal_journal_entry_id BIGINT UNSIGNED NULL AFTER reversal_journal_entry_id'
                );
            }

            $db->exec(
                'UPDATE treasury_transactions
                 SET
                    source_branch_id = COALESCE(source_branch_id, branch_id),
                    destination_branch_id = COALESCE(destination_branch_id, branch_id),
                    source_currency = COALESCE(NULLIF(source_currency, ""), currency),
                    destination_currency = COALESCE(NULLIF(destination_currency, ""), currency),
                    source_amount = COALESCE(source_amount, amount),
                    destination_amount = COALESCE(destination_amount, amount),
                    exchange_rate = COALESCE(exchange_rate, 1.00000000),
                    exchange_rate_effective_date = COALESCE(exchange_rate_effective_date, transaction_date)
                 WHERE
                    source_branch_id IS NULL
                    OR destination_branch_id IS NULL
                    OR source_currency IS NULL
                    OR destination_currency IS NULL
                    OR source_amount IS NULL
                    OR destination_amount IS NULL
                    OR exchange_rate IS NULL
                    OR exchange_rate_effective_date IS NULL'
            );

            $indexes = $indexesFor('treasury_transactions');
            if (! isset($indexes['idx_treasury_transactions_source_branch'])) {
                $db->exec('CREATE INDEX idx_treasury_transactions_source_branch ON treasury_transactions (source_branch_id)');
            }
            if (! isset($indexes['idx_treasury_transactions_destination_branch'])) {
                $db->exec('CREATE INDEX idx_treasury_transactions_destination_branch ON treasury_transactions (destination_branch_id)');
            }
            if (! isset($indexes['idx_treasury_transactions_destination_journal'])) {
                $db->exec('CREATE INDEX idx_treasury_transactions_destination_journal ON treasury_transactions (destination_journal_entry_id)');
            }
            if (! isset($indexes['idx_treasury_transactions_destination_reversal_journal'])) {
                $db->exec('CREATE INDEX idx_treasury_transactions_destination_reversal_journal ON treasury_transactions (destination_reversal_journal_entry_id)');
            }

            $foreignKeys = $foreignKeysFor('treasury_transactions');
            if (! isset($foreignKeys['fk_treasury_transactions_source_branch'])) {
                $db->exec(
                    'ALTER TABLE treasury_transactions
                     ADD CONSTRAINT fk_treasury_transactions_source_branch
                         FOREIGN KEY (source_branch_id) REFERENCES branches (id) ON DELETE SET NULL'
                );
            }
            if (! isset($foreignKeys['fk_treasury_transactions_destination_branch'])) {
                $db->exec(
                    'ALTER TABLE treasury_transactions
                     ADD CONSTRAINT fk_treasury_transactions_destination_branch
                         FOREIGN KEY (destination_branch_id) REFERENCES branches (id) ON DELETE SET NULL'
                );
            }
            if (! isset($foreignKeys['fk_treasury_transactions_destination_journal'])) {
                $db->exec(
                    'ALTER TABLE treasury_transactions
                     ADD CONSTRAINT fk_treasury_transactions_destination_journal
                         FOREIGN KEY (destination_journal_entry_id) REFERENCES journal_entries (id) ON DELETE SET NULL'
                );
            }
            if (! isset($foreignKeys['fk_treasury_transactions_destination_reversal_journal'])) {
                $db->exec(
                    'ALTER TABLE treasury_transactions
                     ADD CONSTRAINT fk_treasury_transactions_destination_reversal_journal
                         FOREIGN KEY (destination_reversal_journal_entry_id) REFERENCES journal_entries (id) ON DELETE SET NULL'
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

            foreach ([
                'fk_treasury_transactions_destination_reversal_journal',
                'fk_treasury_transactions_destination_journal',
                'fk_treasury_transactions_destination_branch',
                'fk_treasury_transactions_source_branch',
            ] as $foreignKey) {
                if (isset($foreignKeys[$foreignKey])) {
                    $db->exec('ALTER TABLE treasury_transactions DROP FOREIGN KEY ' . $foreignKey);
                }
            }

            foreach ([
                'idx_treasury_transactions_destination_reversal_journal',
                'idx_treasury_transactions_destination_journal',
                'idx_treasury_transactions_destination_branch',
                'idx_treasury_transactions_source_branch',
            ] as $indexName) {
                if (isset($indexes[$indexName])) {
                    $db->exec('ALTER TABLE treasury_transactions DROP INDEX ' . $indexName);
                }
            }

            $columns = [];
            foreach (($db->query('SHOW COLUMNS FROM treasury_transactions')->fetchAll(\PDO::FETCH_ASSOC) ?: []) as $column) {
                $columns[(string) ($column['Field'] ?? '')] = true;
            }

            foreach ([
                'destination_reversal_journal_entry_id',
                'destination_journal_entry_id',
                'exchange_rate_effective_date',
                'exchange_rate',
                'destination_amount',
                'source_amount',
                'destination_currency',
                'source_currency',
                'destination_branch_id',
                'source_branch_id',
            ] as $columnName) {
                if (isset($columns[$columnName])) {
                    $db->exec('ALTER TABLE treasury_transactions DROP COLUMN ' . $columnName);
                }
            }
        },
    ],
];
