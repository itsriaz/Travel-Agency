<?php

declare(strict_types=1);

return [
    'up' => [
        static function (\PDO $db): void {
            $columnCheck = $db->prepare(
                'SELECT COUNT(*)
                 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = :table_name
                   AND COLUMN_NAME = :column_name'
            );
            $columnCheck->execute([
                'table_name' => 'users',
                'column_name' => 'username',
            ]);

            if ((int) $columnCheck->fetchColumn() === 0) {
                $db->exec('ALTER TABLE users ADD COLUMN username VARCHAR(100) NULL AFTER name');
            }

            $indexCheck = $db->prepare(
                'SELECT COUNT(*)
                 FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = :table_name
                   AND INDEX_NAME = :index_name'
            );
            $indexCheck->execute([
                'table_name' => 'users',
                'index_name' => 'uniq_users_username',
            ]);

            if ((int) $indexCheck->fetchColumn() === 0) {
                $db->exec('ALTER TABLE users ADD UNIQUE KEY uniq_users_username (username)');
            }

            $db->exec("UPDATE roles SET code = 'employee', name = 'Employee' WHERE code = 'branch_user'");

            $db->exec(
                'CREATE TABLE IF NOT EXISTS permissions (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    code VARCHAR(100) NOT NULL UNIQUE,
                    name VARCHAR(190) NOT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );

            $db->exec(
                'CREATE TABLE IF NOT EXISTS role_permissions (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    role_id INT UNSIGNED NOT NULL,
                    permission_id INT UNSIGNED NOT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY uniq_role_permission (role_id, permission_id),
                    CONSTRAINT fk_role_permissions_role FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE CASCADE,
                    CONSTRAINT fk_role_permissions_permission FOREIGN KEY (permission_id) REFERENCES permissions (id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );

            $db->exec(
                'CREATE TABLE IF NOT EXISTS auth_login_attempts (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    login_key VARCHAR(190) NOT NULL,
                    ip_address VARCHAR(64) NOT NULL,
                    user_id INT UNSIGNED NULL,
                    was_successful TINYINT(1) NOT NULL DEFAULT 0,
                    attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    KEY idx_auth_attempts_lookup (login_key, ip_address, attempted_at),
                    KEY idx_auth_attempts_user (user_id),
                    CONSTRAINT fk_auth_attempts_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
        },
    ],
    'down' => [
        'DROP TABLE IF EXISTS auth_login_attempts',
        'DROP TABLE IF EXISTS role_permissions',
        'DROP TABLE IF EXISTS permissions',
        'ALTER TABLE users DROP INDEX uniq_users_username',
        'ALTER TABLE users DROP COLUMN username',
    ],
];
