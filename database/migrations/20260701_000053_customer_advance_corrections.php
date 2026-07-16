<?php

declare(strict_types=1);

return [
    'up' => [
        static function (PDO $db): void {
            $db->exec(
                'CREATE TABLE IF NOT EXISTS customer_advance_corrections (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    correction_type ENUM(\'advance_received\', \'advance_returned\') NOT NULL,
                    customer_receipt_id BIGINT UNSIGNED NOT NULL,
                    customer_advance_refund_id BIGINT UNSIGNED NULL,
                    branch_id INT UNSIGNED NOT NULL,
                    traveler_id BIGINT UNSIGNED NOT NULL,
                    old_entry_date DATE NULL,
                    new_entry_date DATE NOT NULL,
                    currency CHAR(3) NOT NULL,
                    old_amount DECIMAL(18,2) NOT NULL DEFAULT 0.00,
                    new_amount DECIMAL(18,2) NOT NULL DEFAULT 0.00,
                    old_payment_method VARCHAR(50) NULL,
                    new_payment_method VARCHAR(50) NOT NULL,
                    old_treasury_account_id BIGINT UNSIGNED NULL,
                    new_treasury_account_id BIGINT UNSIGNED NULL,
                    old_reference_number VARCHAR(100) NULL,
                    new_reference_number VARCHAR(100) NULL,
                    old_remarks TEXT NULL,
                    new_remarks TEXT NULL,
                    reason VARCHAR(1000) NOT NULL,
                    old_journal_entry_id BIGINT UNSIGNED NULL,
                    reversal_journal_entry_id BIGINT UNSIGNED NULL,
                    new_journal_entry_id BIGINT UNSIGNED NULL,
                    created_by_user_id INT UNSIGNED NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_cac_receipt (customer_receipt_id),
                    INDEX idx_cac_refund (customer_advance_refund_id),
                    INDEX idx_cac_branch_date (branch_id, new_entry_date),
                    CONSTRAINT fk_cac_receipt FOREIGN KEY (customer_receipt_id) REFERENCES customer_receipts(id) ON DELETE CASCADE,
                    CONSTRAINT fk_cac_refund FOREIGN KEY (customer_advance_refund_id) REFERENCES customer_advance_refunds(id) ON DELETE SET NULL,
                    CONSTRAINT fk_cac_branch FOREIGN KEY (branch_id) REFERENCES branches(id),
                    CONSTRAINT fk_cac_traveler FOREIGN KEY (traveler_id) REFERENCES travelers(id),
                    CONSTRAINT fk_cac_old_treasury FOREIGN KEY (old_treasury_account_id) REFERENCES treasury_accounts(id) ON DELETE SET NULL,
                    CONSTRAINT fk_cac_new_treasury FOREIGN KEY (new_treasury_account_id) REFERENCES treasury_accounts(id) ON DELETE SET NULL,
                    CONSTRAINT fk_cac_old_journal FOREIGN KEY (old_journal_entry_id) REFERENCES journal_entries(id) ON DELETE SET NULL,
                    CONSTRAINT fk_cac_reversal_journal FOREIGN KEY (reversal_journal_entry_id) REFERENCES journal_entries(id) ON DELETE SET NULL,
                    CONSTRAINT fk_cac_new_journal FOREIGN KEY (new_journal_entry_id) REFERENCES journal_entries(id) ON DELETE SET NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
        },
    ],
    'down' => [
        static function (PDO $db): void {
            $db->exec('DROP TABLE IF EXISTS customer_advance_corrections');
        },
    ],
];
