<?php

declare(strict_types=1);

return [
    'up' => [
        static function (\PDO $db): void {
            $columnExists = static function (string $table, string $column) use ($db): bool {
                $statement = $db->prepare(
                    'SELECT COUNT(*)
                     FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE()
                       AND TABLE_NAME = :table_name
                       AND COLUMN_NAME = :column_name'
                );
                $statement->execute([
                    'table_name' => $table,
                    'column_name' => $column,
                ]);

                return (int) $statement->fetchColumn() > 0;
            };

            if (! $columnExists('branches', 'city')) {
                $db->exec('ALTER TABLE branches ADD COLUMN city VARCHAR(120) NULL AFTER name');
            }

            if (! $columnExists('chart_of_accounts', 'purpose')) {
                $db->exec('ALTER TABLE chart_of_accounts ADD COLUMN purpose VARCHAR(255) NULL AFTER name');
            }

            if (! $columnExists('chart_of_accounts', 'is_active')) {
                $db->exec('ALTER TABLE chart_of_accounts ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER is_system');
            }

            $db->exec(
                'UPDATE chart_of_accounts
                 SET purpose = CASE code
                     WHEN "AR_CONTROL" THEN "Customer service dues by booking and service line"
                     WHEN "AP_CONTROL" THEN "Supplier obligations generated from booking services"
                     WHEN "CUSTOMER_CREDIT" THEN "Unallocated customer money pending allocation"
                     WHEN "SUPPLIER_ADVANCES" THEN "Prepaid supplier balances and running advances"
                     WHEN "CASH_ON_HAND" THEN "Counter cash collections"
                     WHEN "BANK_CLEARING" THEN "Bank transfer and settlement staging"
                     WHEN "CARD_CLEARING" THEN "Card settlement staging account"
                     WHEN "SERVICE_REVENUE" THEN "Customer-side booking revenue"
                     WHEN "SERVICE_COST" THEN "Supplier-side booking cost"
                     WHEN "CARD_CHARGES" THEN "Card charge expense recognition"
                     ELSE purpose
                 END,
                 is_active = 1'
            );
        },
        'CREATE TABLE IF NOT EXISTS currencies (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            code CHAR(3) NOT NULL UNIQUE,
            name VARCHAR(120) NOT NULL,
            symbol VARCHAR(10) NULL,
            reporting_role VARCHAR(190) NOT NULL,
            sort_order INT UNSIGNED NOT NULL DEFAULT 0,
            is_system TINYINT(1) NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_currencies_active (is_active),
            KEY idx_currencies_order (sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        'CREATE TABLE IF NOT EXISTS service_types (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(20) NOT NULL UNIQUE,
            name VARCHAR(120) NOT NULL,
            posting_mode VARCHAR(190) NOT NULL,
            sort_order INT UNSIGNED NOT NULL DEFAULT 0,
            is_system TINYINT(1) NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_service_types_active (is_active),
            KEY idx_service_types_order (sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        'CREATE TABLE IF NOT EXISTS payment_methods (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(50) NOT NULL UNIQUE,
            name VARCHAR(120) NOT NULL,
            ledger_target VARCHAR(120) NOT NULL,
            charges_target VARCHAR(120) NULL,
            sort_order INT UNSIGNED NOT NULL DEFAULT 0,
            is_system TINYINT(1) NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_payment_methods_active (is_active),
            KEY idx_payment_methods_order (sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        'CREATE TABLE IF NOT EXISTS supplier_modes (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(50) NOT NULL UNIQUE,
            name VARCHAR(120) NOT NULL,
            behavior VARCHAR(190) NOT NULL,
            sort_order INT UNSIGNED NOT NULL DEFAULT 0,
            is_system TINYINT(1) NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_supplier_modes_active (is_active),
            KEY idx_supplier_modes_order (sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        'CREATE TABLE IF NOT EXISTS document_types (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(50) NOT NULL UNIQUE,
            name VARCHAR(120) NOT NULL,
            linked_area VARCHAR(120) NOT NULL,
            sort_order INT UNSIGNED NOT NULL DEFAULT 0,
            is_system TINYINT(1) NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_document_types_active (is_active),
            KEY idx_document_types_order (sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        'CREATE TABLE IF NOT EXISTS posting_rules (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            event_key VARCHAR(100) NOT NULL UNIQUE,
            event_name VARCHAR(190) NOT NULL,
            source_area VARCHAR(120) NOT NULL,
            financial_effect VARCHAR(255) NOT NULL,
            debit_account_id INT UNSIGNED NOT NULL,
            credit_account_id INT UNSIGNED NOT NULL,
            rule_note TEXT NULL,
            sort_order INT UNSIGNED NOT NULL DEFAULT 0,
            is_system TINYINT(1) NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_posting_rules_active (is_active),
            KEY idx_posting_rules_order (sort_order),
            CONSTRAINT fk_posting_rules_debit_account FOREIGN KEY (debit_account_id) REFERENCES chart_of_accounts (id),
            CONSTRAINT fk_posting_rules_credit_account FOREIGN KEY (credit_account_id) REFERENCES chart_of_accounts (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    ],
    'down' => [
        'DROP TABLE IF EXISTS posting_rules',
        'DROP TABLE IF EXISTS document_types',
        'DROP TABLE IF EXISTS supplier_modes',
        'DROP TABLE IF EXISTS payment_methods',
        'DROP TABLE IF EXISTS service_types',
        'DROP TABLE IF EXISTS currencies',
        static function (\PDO $db): void {
            $columnExists = static function (string $table, string $column) use ($db): bool {
                $statement = $db->prepare(
                    'SELECT COUNT(*)
                     FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE()
                       AND TABLE_NAME = :table_name
                       AND COLUMN_NAME = :column_name'
                );
                $statement->execute([
                    'table_name' => $table,
                    'column_name' => $column,
                ]);

                return (int) $statement->fetchColumn() > 0;
            };

            if ($columnExists('branches', 'city')) {
                $db->exec('ALTER TABLE branches DROP COLUMN city');
            }

            if ($columnExists('chart_of_accounts', 'purpose')) {
                $db->exec('ALTER TABLE chart_of_accounts DROP COLUMN purpose');
            }

            if ($columnExists('chart_of_accounts', 'is_active')) {
                $db->exec('ALTER TABLE chart_of_accounts DROP COLUMN is_active');
            }
        },
    ],
];
