<?php

declare(strict_types=1);

return [
    'up' => [
        static function (\PDO $db): void {
            $tableExists = static function (string $table) use ($db): bool {
                $statement = $db->prepare(
                    'SELECT 1
                     FROM information_schema.TABLES
                     WHERE TABLE_SCHEMA = DATABASE()
                       AND TABLE_NAME = :table_name
                     LIMIT 1'
                );
                $statement->execute(['table_name' => $table]);

                return $statement->fetchColumn() !== false;
            };

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

            $ensureColumn = static function (string $table, string $name, string $definition) use ($db, $columnsFor): void {
                $columns = $columnsFor($table);
                if (isset($columns[$name])) {
                    return;
                }

                $db->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $definition);
            };

            $ensureIndex = static function (string $table, string $indexName, string $columnName) use ($db, $indexesFor): void {
                $indexes = $indexesFor($table);
                if (isset($indexes[$indexName])) {
                    return;
                }

                $db->exec('CREATE INDEX ' . $indexName . ' ON ' . $table . ' (' . $columnName . ')');
            };

            $ensureForeignKey = static function (
                string $table,
                string $constraintName,
                string $columnName,
                string $referencedTable,
                string $referencedColumn
            ) use ($db, $tableExists, $foreignKeysFor): void {
                if (! $tableExists($referencedTable)) {
                    return;
                }

                $foreignKeys = $foreignKeysFor($table);
                if (isset($foreignKeys[$constraintName])) {
                    return;
                }

                $db->exec(
                    'ALTER TABLE ' . $table
                    . ' ADD CONSTRAINT ' . $constraintName
                    . ' FOREIGN KEY (' . $columnName . ') REFERENCES ' . $referencedTable . ' (' . $referencedColumn . ') ON DELETE SET NULL'
                );
            };

            foreach (['customer_receipts', 'supplier_payments'] as $table) {
                $ensureColumn($table, 'void_reason', 'void_reason TEXT NULL');
                $ensureColumn($table, 'voided_by_user_id', 'voided_by_user_id INT UNSIGNED NULL');
                $ensureColumn($table, 'voided_at', 'voided_at DATETIME NULL');
                $ensureColumn($table, 'reversal_reference', 'reversal_reference VARCHAR(100) NULL');
                $ensureColumn($table, 'reversal_journal_entry_id', 'reversal_journal_entry_id BIGINT UNSIGNED NULL');
            }

            $ensureIndex('customer_receipts', 'idx_customer_receipts_voided_by_user', 'voided_by_user_id');
            $ensureIndex('customer_receipts', 'idx_customer_receipts_voided_at', 'voided_at');
            $ensureIndex('customer_receipts', 'idx_customer_receipts_reversal_reference', 'reversal_reference');
            $ensureIndex('customer_receipts', 'idx_customer_receipts_reversal_journal', 'reversal_journal_entry_id');
            $ensureIndex('supplier_payments', 'idx_supplier_payments_voided_by_user', 'voided_by_user_id');
            $ensureIndex('supplier_payments', 'idx_supplier_payments_voided_at', 'voided_at');
            $ensureIndex('supplier_payments', 'idx_supplier_payments_reversal_reference', 'reversal_reference');
            $ensureIndex('supplier_payments', 'idx_supplier_payments_reversal_journal', 'reversal_journal_entry_id');

            $ensureForeignKey('customer_receipts', 'fk_customer_receipts_voided_by_user', 'voided_by_user_id', 'users', 'id');
            $ensureForeignKey('customer_receipts', 'fk_customer_receipts_reversal_journal', 'reversal_journal_entry_id', 'journal_entries', 'id');
            $ensureForeignKey('supplier_payments', 'fk_supplier_payments_voided_by_user', 'voided_by_user_id', 'users', 'id');
            $ensureForeignKey('supplier_payments', 'fk_supplier_payments_reversal_journal', 'reversal_journal_entry_id', 'journal_entries', 'id');
        },
    ],
    'down' => [
        static function (\PDO $db): void {
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

            $columnsFor = static function (string $table) use ($db): array {
                $columns = [];
                foreach (($db->query('SHOW COLUMNS FROM ' . $table)->fetchAll(\PDO::FETCH_ASSOC) ?: []) as $column) {
                    $columns[(string) ($column['Field'] ?? '')] = true;
                }

                return $columns;
            };

            foreach ([
                'customer_receipts' => [
                    'foreign_keys' => [
                        'fk_customer_receipts_voided_by_user',
                        'fk_customer_receipts_reversal_journal',
                    ],
                    'indexes' => [
                        'idx_customer_receipts_voided_by_user',
                        'idx_customer_receipts_voided_at',
                        'idx_customer_receipts_reversal_reference',
                        'idx_customer_receipts_reversal_journal',
                    ],
                ],
                'supplier_payments' => [
                    'foreign_keys' => [
                        'fk_supplier_payments_voided_by_user',
                        'fk_supplier_payments_reversal_journal',
                    ],
                    'indexes' => [
                        'idx_supplier_payments_voided_by_user',
                        'idx_supplier_payments_voided_at',
                        'idx_supplier_payments_reversal_reference',
                        'idx_supplier_payments_reversal_journal',
                    ],
                ],
            ] as $table => $metadata) {
                $foreignKeys = $foreignKeysFor($table);
                foreach ($metadata['foreign_keys'] as $constraintName) {
                    if (isset($foreignKeys[$constraintName])) {
                        $db->exec('ALTER TABLE ' . $table . ' DROP FOREIGN KEY ' . $constraintName);
                    }
                }

                $indexes = $indexesFor($table);
                foreach ($metadata['indexes'] as $indexName) {
                    if (isset($indexes[$indexName])) {
                        $db->exec('ALTER TABLE ' . $table . ' DROP INDEX ' . $indexName);
                    }
                }

                $columns = $columnsFor($table);
                foreach ([
                    'reversal_journal_entry_id',
                    'reversal_reference',
                    'voided_at',
                    'voided_by_user_id',
                    'void_reason',
                ] as $columnName) {
                    if (isset($columns[$columnName])) {
                        $db->exec('ALTER TABLE ' . $table . ' DROP COLUMN ' . $columnName);
                    }
                }
            }
        },
    ],
];
