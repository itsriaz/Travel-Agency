<?php

declare(strict_types=1);

return [
    'up' => [
        static function (\PDO $db): void {
            $columns = [];
            foreach (($db->query('SHOW COLUMNS FROM counterparty_offsets')->fetchAll(\PDO::FETCH_ASSOC) ?: []) as $column) {
                $columns[(string) ($column['Field'] ?? '')] = true;
            }

            $definitions = [
                'account_branch_id' => 'INT UNSIGNED NULL AFTER branch_id',
                'supplier_branch_id' => 'INT UNSIGNED NULL AFTER account_branch_id',
                'account_currency' => 'CHAR(3) NULL AFTER currency',
                'supplier_currency' => 'CHAR(3) NULL AFTER account_currency',
                'account_amount' => 'DECIMAL(18,2) NULL AFTER amount',
                'supplier_amount' => 'DECIMAL(18,2) NULL AFTER account_amount',
                'exchange_rate' => 'DECIMAL(18,8) NULL AFTER supplier_amount',
                'exchange_rate_effective_date' => 'DATE NULL AFTER exchange_rate',
                'supplier_journal_entry_id' => 'BIGINT UNSIGNED NULL AFTER journal_entry_id',
                'supplier_reversal_journal_entry_id' => 'BIGINT UNSIGNED NULL AFTER reversal_journal_entry_id',
            ];
            foreach ($definitions as $name => $definition) {
                if (! isset($columns[$name])) {
                    $db->exec('ALTER TABLE counterparty_offsets ADD COLUMN ' . $name . ' ' . $definition);
                }
            }

            $db->exec(
                'UPDATE counterparty_offsets SET
                    account_branch_id = COALESCE(account_branch_id, branch_id),
                    supplier_branch_id = COALESCE(supplier_branch_id, branch_id),
                    account_currency = COALESCE(NULLIF(account_currency, ""), currency),
                    supplier_currency = COALESCE(NULLIF(supplier_currency, ""), currency),
                    account_amount = COALESCE(account_amount, amount),
                    supplier_amount = COALESCE(supplier_amount, amount),
                    exchange_rate = COALESCE(exchange_rate, 1.00000000),
                    exchange_rate_effective_date = COALESCE(exchange_rate_effective_date, offset_date)'
            );

            $indexes = [];
            foreach (($db->query('SHOW INDEX FROM counterparty_offsets')->fetchAll(\PDO::FETCH_ASSOC) ?: []) as $row) {
                $indexes[(string) ($row['Key_name'] ?? '')] = true;
            }
            foreach ([
                'idx_counterparty_offsets_account_dimension' => '(account_branch_id, account_currency, status)',
                'idx_counterparty_offsets_supplier_dimension' => '(supplier_branch_id, supplier_currency, status)',
                'idx_counterparty_offsets_supplier_journal' => '(supplier_journal_entry_id)',
                'idx_counterparty_offsets_supplier_reversal' => '(supplier_reversal_journal_entry_id)',
            ] as $name => $definition) {
                if (! isset($indexes[$name])) {
                    $db->exec('CREATE INDEX ' . $name . ' ON counterparty_offsets ' . $definition);
                }
            }

            $foreignKeys = [];
            $statement = $db->prepare(
                'SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = "counterparty_offsets"
                   AND CONSTRAINT_TYPE = "FOREIGN KEY"'
            );
            $statement->execute();
            foreach (($statement->fetchAll(\PDO::FETCH_ASSOC) ?: []) as $row) {
                $foreignKeys[(string) ($row['CONSTRAINT_NAME'] ?? '')] = true;
            }
            foreach ([
                'fk_counterparty_offsets_account_branch' => 'FOREIGN KEY (account_branch_id) REFERENCES branches (id) ON DELETE SET NULL',
                'fk_counterparty_offsets_supplier_branch' => 'FOREIGN KEY (supplier_branch_id) REFERENCES branches (id) ON DELETE SET NULL',
                'fk_counterparty_offsets_supplier_journal' => 'FOREIGN KEY (supplier_journal_entry_id) REFERENCES journal_entries (id) ON DELETE SET NULL',
                'fk_counterparty_offsets_supplier_reversal' => 'FOREIGN KEY (supplier_reversal_journal_entry_id) REFERENCES journal_entries (id) ON DELETE SET NULL',
            ] as $name => $definition) {
                if (! isset($foreignKeys[$name])) {
                    $db->exec('ALTER TABLE counterparty_offsets ADD CONSTRAINT ' . $name . ' ' . $definition);
                }
            }

            $account = $db->prepare(
                'INSERT INTO chart_of_accounts (code, name, account_type, normal_balance, is_system, is_active)
                 SELECT :code, :name, "asset", "debit", 1, 1
                 WHERE NOT EXISTS (SELECT 1 FROM chart_of_accounts WHERE code = :lookup_code)'
            );
            $account->execute([
                'code' => 'LINKED_PARTY_CLEARING',
                'name' => 'Linked Party Settlement Clearing',
                'lookup_code' => 'LINKED_PARTY_CLEARING',
            ]);
        },
    ],
    'down' => [
        static function (\PDO $db): void {
            $foreignKeys = [];
            $statement = $db->prepare(
                'SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = "counterparty_offsets"
                   AND CONSTRAINT_TYPE = "FOREIGN KEY"'
            );
            $statement->execute();
            foreach (($statement->fetchAll(\PDO::FETCH_ASSOC) ?: []) as $row) {
                $foreignKeys[(string) ($row['CONSTRAINT_NAME'] ?? '')] = true;
            }
            foreach ([
                'fk_counterparty_offsets_supplier_reversal',
                'fk_counterparty_offsets_supplier_journal',
                'fk_counterparty_offsets_supplier_branch',
                'fk_counterparty_offsets_account_branch',
            ] as $name) {
                if (isset($foreignKeys[$name])) {
                    $db->exec('ALTER TABLE counterparty_offsets DROP FOREIGN KEY ' . $name);
                }
            }

            $indexes = [];
            foreach (($db->query('SHOW INDEX FROM counterparty_offsets')->fetchAll(\PDO::FETCH_ASSOC) ?: []) as $row) {
                $indexes[(string) ($row['Key_name'] ?? '')] = true;
            }
            foreach ([
                'idx_counterparty_offsets_supplier_reversal',
                'idx_counterparty_offsets_supplier_journal',
                'idx_counterparty_offsets_supplier_dimension',
                'idx_counterparty_offsets_account_dimension',
            ] as $name) {
                if (isset($indexes[$name])) {
                    $db->exec('ALTER TABLE counterparty_offsets DROP INDEX ' . $name);
                }
            }

            $columns = [];
            foreach (($db->query('SHOW COLUMNS FROM counterparty_offsets')->fetchAll(\PDO::FETCH_ASSOC) ?: []) as $column) {
                $columns[(string) ($column['Field'] ?? '')] = true;
            }
            foreach ([
                'supplier_reversal_journal_entry_id', 'supplier_journal_entry_id',
                'exchange_rate_effective_date', 'exchange_rate', 'supplier_amount', 'account_amount',
                'supplier_currency', 'account_currency', 'supplier_branch_id', 'account_branch_id',
            ] as $name) {
                if (isset($columns[$name])) {
                    $db->exec('ALTER TABLE counterparty_offsets DROP COLUMN ' . $name);
                }
            }
        },
    ],
];
