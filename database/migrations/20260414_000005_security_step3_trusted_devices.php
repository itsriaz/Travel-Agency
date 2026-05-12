<?php

declare(strict_types=1);

return [
    'up' => [
        static function (\PDO $db): void {
            $db->exec(
                'CREATE TABLE IF NOT EXISTS trusted_devices (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    user_id INT UNSIGNED NOT NULL,
                    selector VARCHAR(64) NOT NULL UNIQUE,
                    token_hash VARCHAR(255) NOT NULL,
                    device_label VARCHAR(190) NOT NULL,
                    user_agent_hash VARCHAR(64) NOT NULL,
                    last_ip_address VARCHAR(64) NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    last_used_at DATETIME NULL,
                    expires_at DATETIME NOT NULL,
                    revoked_at DATETIME NULL,
                    CONSTRAINT fk_trusted_devices_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
                    KEY idx_trusted_devices_user (user_id),
                    KEY idx_trusted_devices_expiry (expires_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
        },
    ],
    'down' => [
        'DROP TABLE IF EXISTS trusted_devices',
    ],
];
