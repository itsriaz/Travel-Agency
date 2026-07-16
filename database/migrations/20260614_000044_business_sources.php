<?php

declare(strict_types=1);

return [
    'up' => [
        static function (PDO $db): void {
            $db->exec(
                'CREATE TABLE IF NOT EXISTS business_sources (
                    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    code VARCHAR(50) NOT NULL UNIQUE,
                    name VARCHAR(190) NOT NULL UNIQUE,
                    phone VARCHAR(50) NULL,
                    address VARCHAR(255) NULL,
                    description VARCHAR(2000) NULL,
                    is_system TINYINT(1) NOT NULL DEFAULT 0,
                    is_active TINYINT(1) NOT NULL DEFAULT 1,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_business_sources_active_name (is_active, name)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );

            $db->exec(
                'ALTER TABLE bookings
                    ADD COLUMN business_source_id INT UNSIGNED NULL AFTER branch_id'
            );
        },
        static function (PDO $db): void {
            $columnStatement = $db->query(
                "SELECT COUNT(*) FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'bookings'
                   AND INDEX_NAME = 'idx_bookings_business_source_id'"
            );
            $hasIndex = (int) ($columnStatement ? $columnStatement->fetchColumn() : 0) > 0;
            if (! $hasIndex) {
                $db->exec('ALTER TABLE bookings ADD INDEX idx_bookings_business_source_id (business_source_id)');
            }

            $fkStatement = $db->query(
                "SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS
                 WHERE CONSTRAINT_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'bookings'
                   AND CONSTRAINT_NAME = 'fk_bookings_business_source'"
            );
            $hasForeignKey = (int) ($fkStatement ? $fkStatement->fetchColumn() : 0) > 0;
            if (! $hasForeignKey) {
                $db->exec(
                    'ALTER TABLE bookings
                        ADD CONSTRAINT fk_bookings_business_source
                            FOREIGN KEY (business_source_id) REFERENCES business_sources (id)
                            ON UPDATE CASCADE
                            ON DELETE RESTRICT'
                );
            }
        },
        static function (PDO $db): void {
            $insert = $db->prepare(
                'INSERT INTO business_sources (code, name, description, is_system, is_active)
                 VALUES (:code, :name, :description, 1, 1)
                 ON DUPLICATE KEY UPDATE
                    description = VALUES(description),
                    is_active = 1'
            );
            $insert->execute([
                'code' => 'walking_client',
                'name' => 'Walking Client',
                'description' => 'System default account for walk-in or unassigned business source.',
            ]);

            $defaultIdStatement = $db->prepare('SELECT id FROM business_sources WHERE code = :code LIMIT 1');
            $defaultIdStatement->execute(['code' => 'walking_client']);
            $defaultId = (int) ($defaultIdStatement->fetchColumn() ?: 0);
            if ($defaultId <= 0) {
                throw new RuntimeException('Default business source could not be created.');
            }

            $update = $db->prepare(
                'UPDATE bookings
                 SET business_source_id = :default_id
                 WHERE business_source_id IS NULL OR business_source_id <= 0'
            );
            $update->execute(['default_id' => $defaultId]);

            $db->exec(
                'ALTER TABLE bookings
                    MODIFY business_source_id INT UNSIGNED NOT NULL'
            );
        },
    ],
    'down' => [
        static function (PDO $db): void {
            $fkStatement = $db->query(
                "SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS
                 WHERE CONSTRAINT_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'bookings'
                   AND CONSTRAINT_NAME = 'fk_bookings_business_source'"
            );
            $hasForeignKey = (int) ($fkStatement ? $fkStatement->fetchColumn() : 0) > 0;
            if ($hasForeignKey) {
                $db->exec('ALTER TABLE bookings DROP FOREIGN KEY fk_bookings_business_source');
            }

            $indexStatement = $db->query(
                "SELECT COUNT(*) FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'bookings'
                   AND INDEX_NAME = 'idx_bookings_business_source_id'"
            );
            $hasIndex = (int) ($indexStatement ? $indexStatement->fetchColumn() : 0) > 0;
            if ($hasIndex) {
                $db->exec('ALTER TABLE bookings DROP INDEX idx_bookings_business_source_id');
            }

            $columnStatement = $db->query(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'bookings'
                   AND COLUMN_NAME = 'business_source_id'"
            );
            $hasColumn = (int) ($columnStatement ? $columnStatement->fetchColumn() : 0) > 0;
            if ($hasColumn) {
                $db->exec('ALTER TABLE bookings DROP COLUMN business_source_id');
            }

            $db->exec('DROP TABLE IF EXISTS business_sources');
        },
    ],
];
