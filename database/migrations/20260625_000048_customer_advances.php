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

            if (! $columnExists('customer_receipts', 'traveler_id')) {
                $db->exec(
                    'ALTER TABLE customer_receipts
                     ADD COLUMN traveler_id BIGINT UNSIGNED NULL AFTER branch_id'
                );
            }

            if (! $columnExists('customer_receipts', 'receipt_purpose')) {
                $db->exec(
                    'ALTER TABLE customer_receipts
                     ADD COLUMN receipt_purpose VARCHAR(40) NOT NULL DEFAULT "booking_payment" AFTER traveler_id'
                );
            }

            if (! $indexExists('customer_receipts', 'idx_customer_receipts_traveler_credit')) {
                $db->exec('CREATE INDEX idx_customer_receipts_traveler_credit ON customer_receipts (branch_id, traveler_id, currency, receipt_purpose, unallocated_amount)');
            }

            if (! $foreignKeyExists('customer_receipts', 'fk_customer_receipts_traveler')) {
                $db->exec(
                    'ALTER TABLE customer_receipts
                     ADD CONSTRAINT fk_customer_receipts_traveler
                     FOREIGN KEY (traveler_id) REFERENCES travelers (id) ON DELETE SET NULL'
                );
            }

            $db->exec(
                'CREATE TABLE IF NOT EXISTS customer_advance_refunds (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    customer_receipt_id BIGINT UNSIGNED NOT NULL,
                    branch_id INT UNSIGNED NOT NULL,
                    traveler_id BIGINT UNSIGNED NOT NULL,
                    refund_date DATE NOT NULL,
                    currency CHAR(3) NOT NULL,
                    amount DECIMAL(18,2) NOT NULL,
                    payment_method VARCHAR(50) NOT NULL,
                    treasury_account_id BIGINT UNSIGNED NULL,
                    reference_number VARCHAR(100) NULL,
                    reason VARCHAR(1000) NOT NULL,
                    remarks VARCHAR(4000) NULL,
                    journal_entry_id BIGINT UNSIGNED NULL,
                    created_by_user_id INT UNSIGNED NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_customer_advance_refunds_receipt (customer_receipt_id),
                    INDEX idx_customer_advance_refunds_traveler (branch_id, traveler_id, currency, refund_date),
                    CONSTRAINT fk_customer_advance_refunds_receipt
                        FOREIGN KEY (customer_receipt_id) REFERENCES customer_receipts (id) ON DELETE CASCADE,
                    CONSTRAINT fk_customer_advance_refunds_branch
                        FOREIGN KEY (branch_id) REFERENCES branches (id),
                    CONSTRAINT fk_customer_advance_refunds_traveler
                        FOREIGN KEY (traveler_id) REFERENCES travelers (id),
                    CONSTRAINT fk_customer_advance_refunds_treasury
                        FOREIGN KEY (treasury_account_id) REFERENCES treasury_accounts (id) ON DELETE SET NULL,
                    CONSTRAINT fk_customer_advance_refunds_journal
                        FOREIGN KEY (journal_entry_id) REFERENCES journal_entries (id) ON DELETE SET NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
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

            $db->exec('DROP TABLE IF EXISTS customer_advance_refunds');

            if ($foreignKeyExists('customer_receipts', 'fk_customer_receipts_traveler')) {
                $db->exec('ALTER TABLE customer_receipts DROP FOREIGN KEY fk_customer_receipts_traveler');
            }

            if ($indexExists('customer_receipts', 'idx_customer_receipts_traveler_credit')) {
                $db->exec('DROP INDEX idx_customer_receipts_traveler_credit ON customer_receipts');
            }

            if ($columnExists('customer_receipts', 'receipt_purpose')) {
                $db->exec('ALTER TABLE customer_receipts DROP COLUMN receipt_purpose');
            }

            if ($columnExists('customer_receipts', 'traveler_id')) {
                $db->exec('ALTER TABLE customer_receipts DROP COLUMN traveler_id');
            }
        },
    ],
];
