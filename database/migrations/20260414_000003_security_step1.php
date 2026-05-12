<?php

declare(strict_types=1);

return [
    'up' => [
        static function (\PDO $db): void {
            $columnCheck = static function (string $table, string $column) use ($db): bool {
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

            if (! $columnCheck('users', 'must_change_password')) {
                $db->exec('ALTER TABLE users ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 1 AFTER password_hash');
            }

            if (! $columnCheck('users', 'force_password_change_reason')) {
                $db->exec("ALTER TABLE users ADD COLUMN force_password_change_reason VARCHAR(50) NULL AFTER must_change_password");
            }

            if (! $columnCheck('users', 'password_changed_at')) {
                $db->exec('ALTER TABLE users ADD COLUMN password_changed_at DATETIME NULL AFTER last_login_at');
            }

            if (! $columnCheck('users', 'password_reset_required_at')) {
                $db->exec('ALTER TABLE users ADD COLUMN password_reset_required_at DATETIME NULL AFTER password_changed_at');
            }

            if (! $columnCheck('users', 'session_version')) {
                $db->exec('ALTER TABLE users ADD COLUMN session_version INT UNSIGNED NOT NULL DEFAULT 1 AFTER password_reset_required_at');
            }

            if (! $columnCheck('users', 'two_factor_enabled')) {
                $db->exec('ALTER TABLE users ADD COLUMN two_factor_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER session_version');
            }

            if (! $columnCheck('users', 'two_factor_confirmed_at')) {
                $db->exec('ALTER TABLE users ADD COLUMN two_factor_confirmed_at DATETIME NULL AFTER two_factor_enabled');
            }

            $db->exec(
                'CREATE TABLE IF NOT EXISTS password_reset_tokens (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    user_id INT UNSIGNED NOT NULL,
                    selector VARCHAR(64) NOT NULL UNIQUE,
                    token_hash VARCHAR(255) NOT NULL,
                    requested_by_ip VARCHAR(64) NULL,
                    requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    expires_at DATETIME NOT NULL,
                    used_at DATETIME NULL,
                    CONSTRAINT fk_password_reset_tokens_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
                    KEY idx_password_reset_user (user_id),
                    KEY idx_password_reset_expiry (expires_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );

            $db->exec(
                'CREATE TABLE IF NOT EXISTS security_throttle_events (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    action_name VARCHAR(100) NOT NULL,
                    subject_key VARCHAR(190) NOT NULL,
                    ip_address VARCHAR(64) NOT NULL,
                    was_successful TINYINT(1) NOT NULL DEFAULT 0,
                    attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    KEY idx_security_throttle_lookup (action_name, subject_key, ip_address, attempted_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
        },
    ],
    'down' => [
        'DROP TABLE IF EXISTS security_throttle_events',
        'DROP TABLE IF EXISTS password_reset_tokens',
    ],
];
