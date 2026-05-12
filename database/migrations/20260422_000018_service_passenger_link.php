<?php

declare(strict_types=1);

return [
    'up' => [
        static function (\PDO $db): void {
            $columnExists = static function (string $column) use ($db): bool {
                $statement = $db->prepare(
                    'SELECT COUNT(*)
                     FROM information_schema.columns
                     WHERE table_schema = DATABASE()
                       AND table_name = :table_name
                       AND column_name = :column_name'
                );
                $statement->execute([
                    'table_name' => 'booking_services',
                    'column_name' => $column,
                ]);

                return (int) $statement->fetchColumn() > 0;
            };

            if (! $columnExists('traveler_id')) {
                $db->exec('ALTER TABLE booking_services ADD COLUMN traveler_id BIGINT UNSIGNED NULL AFTER supplier_name_snapshot');
            }

            if (! $columnExists('passenger_name_snapshot')) {
                $db->exec('ALTER TABLE booking_services ADD COLUMN passenger_name_snapshot VARCHAR(190) NULL AFTER traveler_id');
            }
        },
    ],
    'down' => [
        static function (\PDO $db): void {
            $columnExists = static function (string $column) use ($db): bool {
                $statement = $db->prepare(
                    'SELECT COUNT(*)
                     FROM information_schema.columns
                     WHERE table_schema = DATABASE()
                       AND table_name = :table_name
                       AND column_name = :column_name'
                );
                $statement->execute([
                    'table_name' => 'booking_services',
                    'column_name' => $column,
                ]);

                return (int) $statement->fetchColumn() > 0;
            };

            if ($columnExists('passenger_name_snapshot')) {
                $db->exec('ALTER TABLE booking_services DROP COLUMN passenger_name_snapshot');
            }

            if ($columnExists('traveler_id')) {
                $db->exec('ALTER TABLE booking_services DROP COLUMN traveler_id');
            }
        },
    ],
];
