<?php

declare(strict_types=1);

return [
    'up' => [
        'CREATE TABLE IF NOT EXISTS counterparty_offsets (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            offset_no VARCHAR(50) NOT NULL UNIQUE,
            link_id BIGINT UNSIGNED NULL,
            business_source_id INT UNSIGNED NOT NULL,
            supplier_id INT UNSIGNED NOT NULL,
            branch_id INT UNSIGNED NOT NULL,
            currency CHAR(3) NOT NULL,
            offset_date DATE NOT NULL,
            amount DECIMAL(18,2) NOT NULL,
            status ENUM("posted", "void") NOT NULL DEFAULT "posted",
            reference_no VARCHAR(100) NULL,
            remarks TEXT NULL,
            journal_entry_id BIGINT UNSIGNED NULL,
            reversal_journal_entry_id BIGINT UNSIGNED NULL,
            created_by_user_id INT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            voided_by_user_id INT UNSIGNED NULL,
            voided_at DATETIME NULL,
            void_reason TEXT NULL,
            KEY idx_counterparty_offsets_party (business_source_id, supplier_id),
            KEY idx_counterparty_offsets_branch_currency (branch_id, currency, status),
            KEY idx_counterparty_offsets_date (offset_date),
            CONSTRAINT fk_counterparty_offsets_link FOREIGN KEY (link_id) REFERENCES business_source_supplier_links (id) ON DELETE SET NULL,
            CONSTRAINT fk_counterparty_offsets_business_source FOREIGN KEY (business_source_id) REFERENCES business_sources (id),
            CONSTRAINT fk_counterparty_offsets_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers (id),
            CONSTRAINT fk_counterparty_offsets_branch FOREIGN KEY (branch_id) REFERENCES branches (id),
            CONSTRAINT fk_counterparty_offsets_journal FOREIGN KEY (journal_entry_id) REFERENCES journal_entries (id) ON DELETE SET NULL,
            CONSTRAINT fk_counterparty_offsets_reversal_journal FOREIGN KEY (reversal_journal_entry_id) REFERENCES journal_entries (id) ON DELETE SET NULL,
            CONSTRAINT fk_counterparty_offsets_created_user FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL,
            CONSTRAINT fk_counterparty_offsets_voided_user FOREIGN KEY (voided_by_user_id) REFERENCES users (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        'CREATE TABLE IF NOT EXISTS counterparty_offset_receivable_allocations (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            counterparty_offset_id BIGINT UNSIGNED NOT NULL,
            customer_receivable_item_id BIGINT UNSIGNED NOT NULL,
            allocated_amount DECIMAL(18,2) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_counterparty_offset_receivable (counterparty_offset_id, customer_receivable_item_id),
            KEY idx_counterparty_offset_receivable_item (customer_receivable_item_id),
            CONSTRAINT fk_counterparty_offset_receivable_header FOREIGN KEY (counterparty_offset_id) REFERENCES counterparty_offsets (id) ON DELETE CASCADE,
            CONSTRAINT fk_counterparty_offset_receivable_item FOREIGN KEY (customer_receivable_item_id) REFERENCES customer_receivable_items (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        'CREATE TABLE IF NOT EXISTS counterparty_offset_payable_allocations (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            counterparty_offset_id BIGINT UNSIGNED NOT NULL,
            supplier_obligation_id BIGINT UNSIGNED NOT NULL,
            allocated_amount DECIMAL(18,2) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_counterparty_offset_payable (counterparty_offset_id, supplier_obligation_id),
            KEY idx_counterparty_offset_payable_item (supplier_obligation_id),
            CONSTRAINT fk_counterparty_offset_payable_header FOREIGN KEY (counterparty_offset_id) REFERENCES counterparty_offsets (id) ON DELETE CASCADE,
            CONSTRAINT fk_counterparty_offset_payable_item FOREIGN KEY (supplier_obligation_id) REFERENCES supplier_obligations (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    ],
    'down' => [
        'DROP TABLE IF EXISTS counterparty_offset_payable_allocations',
        'DROP TABLE IF EXISTS counterparty_offset_receivable_allocations',
        'DROP TABLE IF EXISTS counterparty_offsets',
    ],
];
