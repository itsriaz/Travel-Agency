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

            if (! $columnCheck('bookings', 'lead_traveler_id')) {
                $db->exec('ALTER TABLE bookings ADD COLUMN lead_traveler_id BIGINT UNSIGNED NULL AFTER branch_id');
            }
        },
        'CREATE TABLE IF NOT EXISTS travelers (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            branch_id INT UNSIGNED NOT NULL,
            full_name VARCHAR(190) NOT NULL,
            passport_number VARCHAR(50) NULL,
            nationality VARCHAR(120) NULL,
            date_of_birth DATE NULL,
            gender ENUM("male", "female", "other", "unspecified") NOT NULL DEFAULT "unspecified",
            passport_expiry DATE NULL,
            mobile VARCHAR(50) NULL,
            notes TEXT NULL,
            created_by_user_id INT UNSIGNED NULL,
            updated_by_user_id INT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_travelers_branch (branch_id),
            KEY idx_travelers_full_name (full_name),
            KEY idx_travelers_passport (passport_number),
            KEY idx_travelers_mobile (mobile),
            CONSTRAINT fk_travelers_branch FOREIGN KEY (branch_id) REFERENCES branches (id),
            CONSTRAINT fk_travelers_created_by FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL,
            CONSTRAINT fk_travelers_updated_by FOREIGN KEY (updated_by_user_id) REFERENCES users (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        'CREATE TABLE IF NOT EXISTS booking_travelers (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            booking_id BIGINT UNSIGNED NOT NULL,
            traveler_id BIGINT UNSIGNED NOT NULL,
            traveler_role ENUM("lead", "additional") NOT NULL DEFAULT "additional",
            display_order INT UNSIGNED NOT NULL DEFAULT 0,
            attached_by_user_id INT UNSIGNED NULL,
            attached_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_booking_traveler (booking_id, traveler_id),
            KEY idx_booking_travelers_booking (booking_id),
            KEY idx_booking_travelers_traveler (traveler_id),
            KEY idx_booking_travelers_role (traveler_role),
            CONSTRAINT fk_booking_travelers_booking FOREIGN KEY (booking_id) REFERENCES bookings (id) ON DELETE CASCADE,
            CONSTRAINT fk_booking_travelers_traveler FOREIGN KEY (traveler_id) REFERENCES travelers (id) ON DELETE CASCADE,
            CONSTRAINT fk_booking_travelers_attached_by FOREIGN KEY (attached_by_user_id) REFERENCES users (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        static function (\PDO $db): void {
            $fkCheck = $db->prepare(
                'SELECT COUNT(*)
                 FROM information_schema.KEY_COLUMN_USAGE
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = :table_name
                   AND CONSTRAINT_NAME = :constraint_name'
            );
            $fkCheck->execute([
                'table_name' => 'bookings',
                'constraint_name' => 'fk_bookings_lead_traveler',
            ]);

            if ((int) $fkCheck->fetchColumn() === 0) {
                $db->exec(
                    'ALTER TABLE bookings
                     ADD CONSTRAINT fk_bookings_lead_traveler
                     FOREIGN KEY (lead_traveler_id) REFERENCES travelers (id) ON DELETE SET NULL'
                );
            }
        },
    ],
    'down' => [
        static function (\PDO $db): void {
            $fkCheck = $db->prepare(
                'SELECT COUNT(*)
                 FROM information_schema.KEY_COLUMN_USAGE
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = :table_name
                   AND CONSTRAINT_NAME = :constraint_name'
            );
            $fkCheck->execute([
                'table_name' => 'bookings',
                'constraint_name' => 'fk_bookings_lead_traveler',
            ]);

            if ((int) $fkCheck->fetchColumn() > 0) {
                $db->exec('ALTER TABLE bookings DROP FOREIGN KEY fk_bookings_lead_traveler');
            }

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

            if ($columnCheck('bookings', 'lead_traveler_id')) {
                $db->exec('ALTER TABLE bookings DROP COLUMN lead_traveler_id');
            }
        },
        'DROP TABLE IF EXISTS booking_travelers',
        'DROP TABLE IF EXISTS travelers',
    ],
];
