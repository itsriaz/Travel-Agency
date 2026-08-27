<?php

declare(strict_types=1);

return [
    'up' => [
        static function (PDO $db): void {
            $columns = [];
            foreach (($db->query('SHOW COLUMNS FROM treasury_transactions')->fetchAll(PDO::FETCH_ASSOC) ?: []) as $column) {
                $columns[(string) ($column['Field'] ?? '')] = true;
            }

            if (! isset($columns['business_source_id'])) {
                $db->exec(
                    'ALTER TABLE treasury_transactions
                     ADD COLUMN business_source_id INT UNSIGNED NULL AFTER branch_id'
                );
            }

            $indexes = [];
            foreach (($db->query('SHOW INDEX FROM treasury_transactions')->fetchAll(PDO::FETCH_ASSOC) ?: []) as $indexRow) {
                $indexes[(string) ($indexRow['Key_name'] ?? '')] = true;
            }
            if (! isset($indexes['idx_treasury_transactions_business_source'])) {
                $db->exec('CREATE INDEX idx_treasury_transactions_business_source ON treasury_transactions (business_source_id)');
            }

            $foreignKeys = [];
            foreach (($db->query(
                "SELECT CONSTRAINT_NAME
                 FROM information_schema.TABLE_CONSTRAINTS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'treasury_transactions'
                   AND CONSTRAINT_TYPE = 'FOREIGN KEY'"
            )->fetchAll(PDO::FETCH_ASSOC) ?: []) as $foreignKeyRow) {
                $foreignKeys[(string) ($foreignKeyRow['CONSTRAINT_NAME'] ?? '')] = true;
            }
            if (! isset($foreignKeys['fk_treasury_transactions_business_source'])) {
                $db->exec(
                    'ALTER TABLE treasury_transactions
                     ADD CONSTRAINT fk_treasury_transactions_business_source
                         FOREIGN KEY (business_source_id) REFERENCES business_sources (id) ON DELETE SET NULL'
                );
            }

            $db->exec(
                'CREATE TABLE IF NOT EXISTS account_holder_balance_applications (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    branch_id INT UNSIGNED NOT NULL,
                    business_source_id INT UNSIGNED NOT NULL,
                    customer_receipt_id BIGINT UNSIGNED NOT NULL,
                    booking_reference VARCHAR(50) NULL,
                    source_currency CHAR(3) NOT NULL,
                    target_currency CHAR(3) NOT NULL,
                    source_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
                    target_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
                    exchange_rate DECIMAL(18,8) NOT NULL DEFAULT 1,
                    applied_date DATE NOT NULL,
                    remarks VARCHAR(500) NULL,
                    created_by_user_id INT UNSIGNED NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    KEY idx_ahba_party_currency (business_source_id, branch_id, source_currency),
                    KEY idx_ahba_receipt (customer_receipt_id),
                    CONSTRAINT fk_ahba_business_source
                        FOREIGN KEY (business_source_id) REFERENCES business_sources (id) ON DELETE CASCADE,
                    CONSTRAINT fk_ahba_customer_receipt
                        FOREIGN KEY (customer_receipt_id) REFERENCES customer_receipts (id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );

            $paymentMethodColumn = $db->query("SHOW COLUMNS FROM customer_receipts LIKE 'payment_method'")->fetch(PDO::FETCH_ASSOC) ?: [];
            $paymentMethodType = strtolower((string) ($paymentMethodColumn['Type'] ?? ''));
            if (str_starts_with($paymentMethodType, 'enum(') && ! str_contains($paymentMethodType, "'account_holder_balance'")) {
                $db->exec(
                    "ALTER TABLE customer_receipts
                     MODIFY COLUMN payment_method
                     ENUM('cash', 'bank_transfer', 'debit_card', 'credit_card', 'customer_paid_supplier', 'account_holder_balance') NOT NULL"
                );
            }
        },
    ],
    'down' => [
        static function (PDO $db): void {
            $foreignKeys = [];
            foreach (($db->query(
                "SELECT CONSTRAINT_NAME
                 FROM information_schema.TABLE_CONSTRAINTS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'treasury_transactions'
                   AND CONSTRAINT_TYPE = 'FOREIGN KEY'"
            )->fetchAll(PDO::FETCH_ASSOC) ?: []) as $foreignKeyRow) {
                $foreignKeys[(string) ($foreignKeyRow['CONSTRAINT_NAME'] ?? '')] = true;
            }
            if (isset($foreignKeys['fk_treasury_transactions_business_source'])) {
                $db->exec('ALTER TABLE treasury_transactions DROP FOREIGN KEY fk_treasury_transactions_business_source');
            }

            $indexes = [];
            foreach (($db->query('SHOW INDEX FROM treasury_transactions')->fetchAll(PDO::FETCH_ASSOC) ?: []) as $indexRow) {
                $indexes[(string) ($indexRow['Key_name'] ?? '')] = true;
            }
            if (isset($indexes['idx_treasury_transactions_business_source'])) {
                $db->exec('ALTER TABLE treasury_transactions DROP INDEX idx_treasury_transactions_business_source');
            }

            $columns = [];
            foreach (($db->query('SHOW COLUMNS FROM treasury_transactions')->fetchAll(PDO::FETCH_ASSOC) ?: []) as $column) {
                $columns[(string) ($column['Field'] ?? '')] = true;
            }
            if (isset($columns['business_source_id'])) {
                $db->exec('ALTER TABLE treasury_transactions DROP COLUMN business_source_id');
            }

            $db->exec('DROP TABLE IF EXISTS account_holder_balance_applications');
        },
    ],
];
