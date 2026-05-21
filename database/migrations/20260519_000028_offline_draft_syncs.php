<?php

declare(strict_types=1);

return [
    'up' => [
        'CREATE TABLE IF NOT EXISTS offline_draft_syncs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            branch_id INT UNSIGNED NULL,
            client_draft_id VARCHAR(100) NOT NULL,
            draft_type VARCHAR(50) NOT NULL,
            source_device VARCHAR(120) NULL,
            status ENUM("synced", "rejected") NOT NULL,
            server_record_type VARCHAR(50) NULL,
            server_record_id BIGINT UNSIGNED NULL,
            server_reference VARCHAR(100) NULL,
            error_message VARCHAR(255) NULL,
            payload_json JSON NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_offline_draft_user_client (user_id, client_draft_id),
            KEY idx_offline_draft_branch (branch_id),
            KEY idx_offline_draft_status (status),
            CONSTRAINT fk_offline_draft_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
            CONSTRAINT fk_offline_draft_branch FOREIGN KEY (branch_id) REFERENCES branches (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    ],
    'down' => [
        'DROP TABLE IF EXISTS offline_draft_syncs',
    ],
];
