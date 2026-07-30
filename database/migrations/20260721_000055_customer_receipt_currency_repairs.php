<?php

declare(strict_types=1);

return [
    'up' => [
        static function (\PDO $db): void {
            $db->exec(
                'INSERT INTO chart_of_accounts (code, name, account_type, normal_balance, is_system, is_active)
                 VALUES ("FX_GAIN", "Foreign Exchange Gain", "revenue", "credit", 1, 1)
                 ON DUPLICATE KEY UPDATE name = VALUES(name), account_type = VALUES(account_type), normal_balance = VALUES(normal_balance), is_system = 1, is_active = 1'
            );

            $db->exec(
                'CREATE TABLE IF NOT EXISTS customer_receipt_currency_corrections (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    original_receipt_id BIGINT UNSIGNED NOT NULL,
                    replacement_receipt_id BIGINT UNSIGNED NULL,
                    booking_reference VARCHAR(50) NOT NULL,
                    service_line_reference VARCHAR(50) NULL,
                    old_branch_id BIGINT UNSIGNED NOT NULL,
                    new_branch_id BIGINT UNSIGNED NULL,
                    old_currency VARCHAR(3) NOT NULL,
                    new_currency VARCHAR(3) NOT NULL,
                    old_treasury_account_id BIGINT UNSIGNED NOT NULL,
                    new_treasury_account_id BIGINT UNSIGNED NULL,
                    corrected_amount DECIMAL(18,2) NOT NULL,
                    treatment VARCHAR(40) NOT NULL,
                    reversal_journal_entry_id BIGINT UNSIGNED NOT NULL,
                    reason VARCHAR(1000) NOT NULL,
                    created_by_user_id BIGINT UNSIGNED NULL,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY uq_receipt_currency_correction_original (original_receipt_id),
                    KEY idx_receipt_currency_correction_booking (booking_reference),
                    CONSTRAINT fk_receipt_currency_correction_original FOREIGN KEY (original_receipt_id) REFERENCES customer_receipts (id),
                    CONSTRAINT fk_receipt_currency_correction_replacement FOREIGN KEY (replacement_receipt_id) REFERENCES customer_receipts (id) ON DELETE SET NULL,
                    CONSTRAINT fk_receipt_currency_correction_old_treasury FOREIGN KEY (old_treasury_account_id) REFERENCES treasury_accounts (id),
                    CONSTRAINT fk_receipt_currency_correction_new_treasury FOREIGN KEY (new_treasury_account_id) REFERENCES treasury_accounts (id) ON DELETE SET NULL,
                    CONSTRAINT fk_receipt_currency_correction_journal FOREIGN KEY (reversal_journal_entry_id) REFERENCES journal_entries (id)
                 ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );

            $db->exec(
                'CREATE TABLE IF NOT EXISTS customer_credit_income_recognitions (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    customer_receipt_id BIGINT UNSIGNED NOT NULL,
                    booking_reference VARCHAR(50) NULL,
                    currency VARCHAR(3) NOT NULL,
                    amount DECIMAL(18,2) NOT NULL,
                    income_account_code VARCHAR(50) NOT NULL,
                    journal_entry_id BIGINT UNSIGNED NOT NULL,
                    reason VARCHAR(1000) NOT NULL,
                    created_by_user_id BIGINT UNSIGNED NULL,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY uq_credit_income_receipt_account (customer_receipt_id, income_account_code),
                    CONSTRAINT fk_credit_income_receipt FOREIGN KEY (customer_receipt_id) REFERENCES customer_receipts (id),
                    CONSTRAINT fk_credit_income_journal FOREIGN KEY (journal_entry_id) REFERENCES journal_entries (id)
                 ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
        },
    ],
    'down' => [
        static function (\PDO $db): void {
            $db->exec('DROP TABLE IF EXISTS customer_credit_income_recognitions');
            $db->exec('DROP TABLE IF EXISTS customer_receipt_currency_corrections');
            $db->exec('DELETE FROM chart_of_accounts WHERE code = "FX_GAIN"');
        },
    ],
];
