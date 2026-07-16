<?php

declare(strict_types=1);

return [
    'up' => [
        static function (PDO $db): void {
            $db->exec(
                'CREATE TABLE IF NOT EXISTS business_expense_corrections (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    business_expense_id BIGINT UNSIGNED NOT NULL,
                    branch_id INT UNSIGNED NOT NULL,
                    correction_date DATE NOT NULL,
                    correction_reason VARCHAR(1000) NOT NULL,
                    correction_note VARCHAR(4000) NULL,
                    prior_expense_date DATE NOT NULL,
                    new_expense_date DATE NOT NULL,
                    prior_category_id INT UNSIGNED NOT NULL,
                    new_category_id INT UNSIGNED NOT NULL,
                    prior_category_name VARCHAR(120) NOT NULL,
                    new_category_name VARCHAR(120) NOT NULL,
                    prior_title VARCHAR(190) NOT NULL,
                    new_title VARCHAR(190) NOT NULL,
                    prior_amount DECIMAL(18,2) NOT NULL DEFAULT 0.00,
                    new_amount DECIMAL(18,2) NOT NULL DEFAULT 0.00,
                    prior_currency CHAR(3) NOT NULL,
                    new_currency CHAR(3) NOT NULL,
                    prior_payment_method VARCHAR(50) NOT NULL,
                    new_payment_method VARCHAR(50) NOT NULL,
                    prior_treasury_account_id BIGINT UNSIGNED NULL,
                    new_treasury_account_id BIGINT UNSIGNED NULL,
                    prior_treasury_account_name VARCHAR(190) NULL,
                    new_treasury_account_name VARCHAR(190) NULL,
                    prior_paid_to_name VARCHAR(190) NULL,
                    new_paid_to_name VARCHAR(190) NULL,
                    prior_reference_number VARCHAR(120) NULL,
                    new_reference_number VARCHAR(120) NULL,
                    prior_notes TEXT NULL,
                    new_notes TEXT NULL,
                    prior_expense_status VARCHAR(20) NOT NULL,
                    new_expense_status VARCHAR(20) NOT NULL,
                    prior_journal_entry_id BIGINT UNSIGNED NULL,
                    reversal_journal_entry_id BIGINT UNSIGNED NULL,
                    new_journal_entry_id BIGINT UNSIGNED NULL,
                    created_by_user_id INT UNSIGNED NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_business_expense_corrections_expense (business_expense_id),
                    INDEX idx_business_expense_corrections_branch_date (branch_id, correction_date),
                    CONSTRAINT fk_business_expense_corrections_expense
                        FOREIGN KEY (business_expense_id) REFERENCES business_expenses (id) ON DELETE CASCADE,
                    CONSTRAINT fk_business_expense_corrections_branch
                        FOREIGN KEY (branch_id) REFERENCES branches (id),
                    CONSTRAINT fk_business_expense_corrections_prior_journal
                        FOREIGN KEY (prior_journal_entry_id) REFERENCES journal_entries (id) ON DELETE SET NULL,
                    CONSTRAINT fk_business_expense_corrections_reversal_journal
                        FOREIGN KEY (reversal_journal_entry_id) REFERENCES journal_entries (id) ON DELETE SET NULL,
                    CONSTRAINT fk_business_expense_corrections_new_journal
                        FOREIGN KEY (new_journal_entry_id) REFERENCES journal_entries (id) ON DELETE SET NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
        },
    ],
    'down' => [
        static function (PDO $db): void {
            $db->exec('DROP TABLE IF EXISTS business_expense_corrections');
        },
    ],
];
