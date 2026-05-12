<?php

declare(strict_types=1);

return [
    'up' => [
        'CREATE TABLE IF NOT EXISTS chart_of_accounts (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(50) NOT NULL UNIQUE,
            name VARCHAR(190) NOT NULL,
            account_type ENUM("asset", "liability", "equity", "revenue", "expense") NOT NULL,
            normal_balance ENUM("debit", "credit") NOT NULL,
            is_system TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        'CREATE TABLE IF NOT EXISTS journal_entries (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            branch_id INT UNSIGNED NOT NULL,
            booking_reference VARCHAR(50) NULL,
            source_type VARCHAR(100) NOT NULL,
            source_reference VARCHAR(100) NULL,
            entry_date DATE NOT NULL,
            currency CHAR(3) NOT NULL,
            narration VARCHAR(255) NOT NULL,
            posted_by_user_id INT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_journal_entries_branch (branch_id),
            KEY idx_journal_entries_booking (booking_reference),
            KEY idx_journal_entries_source (source_type, source_reference),
            CONSTRAINT fk_journal_entries_branch FOREIGN KEY (branch_id) REFERENCES branches (id),
            CONSTRAINT fk_journal_entries_user FOREIGN KEY (posted_by_user_id) REFERENCES users (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        'CREATE TABLE IF NOT EXISTS journal_entry_lines (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            journal_entry_id BIGINT UNSIGNED NOT NULL,
            account_id INT UNSIGNED NOT NULL,
            service_line_reference VARCHAR(50) NULL,
            supplier_obligation_id BIGINT UNSIGNED NULL,
            customer_receivable_item_id BIGINT UNSIGNED NULL,
            customer_receipt_id BIGINT UNSIGNED NULL,
            line_description VARCHAR(255) NULL,
            debit_amount DECIMAL(18,2) NOT NULL DEFAULT 0.00,
            credit_amount DECIMAL(18,2) NOT NULL DEFAULT 0.00,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_journal_entry_lines_entry (journal_entry_id),
            KEY idx_journal_entry_lines_account (account_id),
            KEY idx_journal_entry_lines_service_line (service_line_reference),
            CONSTRAINT fk_journal_entry_lines_entry FOREIGN KEY (journal_entry_id) REFERENCES journal_entries (id) ON DELETE CASCADE,
            CONSTRAINT fk_journal_entry_lines_account FOREIGN KEY (account_id) REFERENCES chart_of_accounts (id),
            CONSTRAINT fk_journal_entry_lines_supplier_obligation FOREIGN KEY (supplier_obligation_id) REFERENCES supplier_obligations (id) ON DELETE SET NULL,
            CONSTRAINT fk_journal_entry_lines_customer_receivable FOREIGN KEY (customer_receivable_item_id) REFERENCES customer_receivable_items (id) ON DELETE SET NULL,
            CONSTRAINT fk_journal_entry_lines_customer_receipt FOREIGN KEY (customer_receipt_id) REFERENCES customer_receipts (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        'INSERT INTO chart_of_accounts (code, name, account_type, normal_balance, is_system)
         VALUES ("AR_CONTROL", "Accounts Receivable Control", "asset", "debit", 1)
         ON DUPLICATE KEY UPDATE name = VALUES(name)',
        'INSERT INTO chart_of_accounts (code, name, account_type, normal_balance, is_system)
         VALUES ("AP_CONTROL", "Accounts Payable Control", "liability", "credit", 1)
         ON DUPLICATE KEY UPDATE name = VALUES(name)',
        'INSERT INTO chart_of_accounts (code, name, account_type, normal_balance, is_system)
         VALUES ("CUSTOMER_CREDIT", "Customer Credit Liability", "liability", "credit", 1)
         ON DUPLICATE KEY UPDATE name = VALUES(name)',
        'INSERT INTO chart_of_accounts (code, name, account_type, normal_balance, is_system)
         VALUES ("SUPPLIER_ADVANCES", "Supplier Advances", "asset", "debit", 1)
         ON DUPLICATE KEY UPDATE name = VALUES(name)',
        'INSERT INTO chart_of_accounts (code, name, account_type, normal_balance, is_system)
         VALUES ("CASH_ON_HAND", "Cash On Hand", "asset", "debit", 1)
         ON DUPLICATE KEY UPDATE name = VALUES(name)',
        'INSERT INTO chart_of_accounts (code, name, account_type, normal_balance, is_system)
         VALUES ("BANK_CLEARING", "Bank Clearing", "asset", "debit", 1)
         ON DUPLICATE KEY UPDATE name = VALUES(name)',
        'INSERT INTO chart_of_accounts (code, name, account_type, normal_balance, is_system)
         VALUES ("CARD_CLEARING", "Card Clearing", "asset", "debit", 1)
         ON DUPLICATE KEY UPDATE name = VALUES(name)',
        'INSERT INTO chart_of_accounts (code, name, account_type, normal_balance, is_system)
         VALUES ("SERVICE_REVENUE", "Service Revenue", "revenue", "credit", 1)
         ON DUPLICATE KEY UPDATE name = VALUES(name)',
        'INSERT INTO chart_of_accounts (code, name, account_type, normal_balance, is_system)
         VALUES ("SERVICE_COST", "Service Cost", "expense", "debit", 1)
         ON DUPLICATE KEY UPDATE name = VALUES(name)',
        'INSERT INTO chart_of_accounts (code, name, account_type, normal_balance, is_system)
         VALUES ("CARD_CHARGES", "Card Charges Expense", "expense", "debit", 1)
         ON DUPLICATE KEY UPDATE name = VALUES(name)',
    ],
    'down' => [
        'DROP TABLE IF EXISTS journal_entry_lines',
        'DROP TABLE IF EXISTS journal_entries',
        'DROP TABLE IF EXISTS chart_of_accounts',
    ],
];
