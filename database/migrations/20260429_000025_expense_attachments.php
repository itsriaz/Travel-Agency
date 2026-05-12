<?php

declare(strict_types=1);

return [
    'up' => [
        'CREATE TABLE IF NOT EXISTS expense_attachments (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            business_expense_id BIGINT UNSIGNED NOT NULL,
            branch_id INT UNSIGNED NOT NULL,
            original_file_name VARCHAR(255) NOT NULL,
            stored_file_name VARCHAR(255) NOT NULL,
            storage_disk VARCHAR(20) NOT NULL DEFAULT "local",
            storage_path VARCHAR(500) NOT NULL,
            mime_type VARCHAR(120) NOT NULL,
            file_extension VARCHAR(20) NOT NULL,
            file_size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
            sha256_hash CHAR(64) NOT NULL,
            visibility VARCHAR(20) NOT NULL DEFAULT "private",
            status VARCHAR(20) NOT NULL DEFAULT "active",
            replaced_attachment_id BIGINT UNSIGNED NULL,
            uploaded_by_user_id INT UNSIGNED NULL,
            updated_by_user_id INT UNSIGNED NULL,
            revoked_by_user_id INT UNSIGNED NULL,
            revoked_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_expense_attachments_expense (business_expense_id),
            KEY idx_expense_attachments_branch (branch_id),
            KEY idx_expense_attachments_status (status),
            KEY idx_expense_attachments_hash (sha256_hash),
            CONSTRAINT fk_expense_attachments_expense FOREIGN KEY (business_expense_id) REFERENCES business_expenses (id) ON DELETE CASCADE,
            CONSTRAINT fk_expense_attachments_branch FOREIGN KEY (branch_id) REFERENCES branches (id),
            CONSTRAINT fk_expense_attachments_replaced FOREIGN KEY (replaced_attachment_id) REFERENCES expense_attachments (id) ON DELETE SET NULL,
            CONSTRAINT fk_expense_attachments_uploaded_by FOREIGN KEY (uploaded_by_user_id) REFERENCES users (id) ON DELETE SET NULL,
            CONSTRAINT fk_expense_attachments_updated_by FOREIGN KEY (updated_by_user_id) REFERENCES users (id) ON DELETE SET NULL,
            CONSTRAINT fk_expense_attachments_revoked_by FOREIGN KEY (revoked_by_user_id) REFERENCES users (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    ],
    'down' => [
        'DROP TABLE IF EXISTS expense_attachments',
    ],
];
