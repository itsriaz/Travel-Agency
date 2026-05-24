-- Treasury P1: Cash and Bank Account Management
-- Safe additive patch: creates new tables only.
-- Does not modify existing tables and does not delete data.

CREATE TABLE IF NOT EXISTS treasury_accounts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    branch_id INT UNSIGNED NOT NULL,
    linked_account_id INT UNSIGNED NULL,
    account_type ENUM('cash','bank','wallet','card_clearing','bank_clearing') NOT NULL,
    account_name VARCHAR(190) NOT NULL,
    account_code VARCHAR(80) NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'PKR',
    bank_name VARCHAR(190) NULL,
    account_number VARCHAR(120) NULL,
    iban VARCHAR(120) NULL,
    opening_balance DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    opening_balance_date DATE NULL,
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    notes VARCHAR(500) NULL,
    created_by_user_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_treasury_account_code (account_code),
    KEY idx_treasury_accounts_branch (branch_id),
    KEY idx_treasury_accounts_type (account_type),
    KEY idx_treasury_accounts_currency (currency),
    KEY idx_treasury_accounts_active (is_active),
    KEY idx_treasury_accounts_linked_account (linked_account_id),
    CONSTRAINT fk_treasury_accounts_branch
        FOREIGN KEY (branch_id) REFERENCES branches(id),
    CONSTRAINT fk_treasury_accounts_chart
        FOREIGN KEY (linked_account_id) REFERENCES chart_of_accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS treasury_transactions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    branch_id INT UNSIGNED NOT NULL,
    transaction_type ENUM(
        'opening_balance',
        'cash_deposit_to_bank',
        'bank_withdrawal_to_cash',
        'bank_to_bank_transfer',
        'cash_to_cash_transfer',
        'adjustment_increase',
        'adjustment_decrease'
    ) NOT NULL,
    transaction_date DATE NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'PKR',
    from_treasury_account_id BIGINT UNSIGNED NULL,
    to_treasury_account_id BIGINT UNSIGNED NULL,
    amount DECIMAL(18,2) NOT NULL,
    reference_no VARCHAR(100) NULL,
    narration VARCHAR(500) NULL,
    journal_entry_id BIGINT UNSIGNED NULL,
    status ENUM('posted','void') NOT NULL DEFAULT 'posted',
    voided_at DATETIME NULL,
    voided_by_user_id INT UNSIGNED NULL,
    void_reason VARCHAR(500) NULL,
    created_by_user_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_treasury_transactions_branch (branch_id),
    KEY idx_treasury_transactions_date (transaction_date),
    KEY idx_treasury_transactions_type (transaction_type),
    KEY idx_treasury_transactions_currency (currency),
    KEY idx_treasury_transactions_from_account (from_treasury_account_id),
    KEY idx_treasury_transactions_to_account (to_treasury_account_id),
    KEY idx_treasury_transactions_journal (journal_entry_id),
    KEY idx_treasury_transactions_status (status),
    CONSTRAINT fk_treasury_transactions_branch
        FOREIGN KEY (branch_id) REFERENCES branches(id),
    CONSTRAINT fk_treasury_transactions_from_account
        FOREIGN KEY (from_treasury_account_id) REFERENCES treasury_accounts(id),
    CONSTRAINT fk_treasury_transactions_to_account
        FOREIGN KEY (to_treasury_account_id) REFERENCES treasury_accounts(id),
    CONSTRAINT fk_treasury_transactions_journal
        FOREIGN KEY (journal_entry_id) REFERENCES journal_entries(id),
    CONSTRAINT chk_treasury_transactions_amount_positive
        CHECK (amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
