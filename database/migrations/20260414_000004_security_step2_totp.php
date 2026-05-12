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

            if (! $columnCheck('users', 'two_factor_secret_encrypted')) {
                $db->exec('ALTER TABLE users ADD COLUMN two_factor_secret_encrypted TEXT NULL AFTER two_factor_confirmed_at');
            }

            if (! $columnCheck('users', 'two_factor_secret_pending_encrypted')) {
                $db->exec('ALTER TABLE users ADD COLUMN two_factor_secret_pending_encrypted TEXT NULL AFTER two_factor_secret_encrypted');
            }

            if (! $columnCheck('users', 'two_factor_setup_started_at')) {
                $db->exec('ALTER TABLE users ADD COLUMN two_factor_setup_started_at DATETIME NULL AFTER two_factor_secret_pending_encrypted');
            }

            $db->exec(
                'CREATE TABLE IF NOT EXISTS user_two_factor_recovery_codes (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    user_id INT UNSIGNED NOT NULL,
                    code_hash VARCHAR(255) NOT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    used_at DATETIME NULL,
                    CONSTRAINT fk_user_two_factor_recovery_codes_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
                    KEY idx_user_two_factor_recovery_codes_user (user_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
        },
    ],
    'down' => [
        'DROP TABLE IF EXISTS user_two_factor_recovery_codes',
    ],
];
