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

            if (! $columnCheck('travelers', 'first_name')) {
                $db->exec('ALTER TABLE travelers ADD COLUMN first_name VARCHAR(100) NULL AFTER branch_id');
            }

            if (! $columnCheck('travelers', 'last_name')) {
                $db->exec('ALTER TABLE travelers ADD COLUMN last_name VARCHAR(100) NULL AFTER first_name');
            }

            if (! $columnCheck('travelers', 'permanent_residence')) {
                $db->exec('ALTER TABLE travelers ADD COLUMN permanent_residence VARCHAR(255) NULL AFTER address');
            }

            if (! $columnCheck('travelers', 'current_residence')) {
                $db->exec('ALTER TABLE travelers ADD COLUMN current_residence VARCHAR(255) NULL AFTER permanent_residence');
            }

            if (! $columnCheck('travelers', 'occupation')) {
                $db->exec('ALTER TABLE travelers ADD COLUMN occupation VARCHAR(120) NULL AFTER current_residence');
            }

            if (! $columnCheck('travelers', 'village')) {
                $db->exec('ALTER TABLE travelers ADD COLUMN village VARCHAR(120) NULL AFTER occupation');
            }

            if (! $columnCheck('travelers', 'district')) {
                $db->exec('ALTER TABLE travelers ADD COLUMN district VARCHAR(120) NULL AFTER village');
            }

            if (! $columnCheck('travelers', 'family_id')) {
                $db->exec('ALTER TABLE travelers ADD COLUMN family_id VARCHAR(60) NULL AFTER district');
            }

            if (! $columnCheck('travelers', 'color_tag')) {
                $db->exec('ALTER TABLE travelers ADD COLUMN color_tag VARCHAR(40) NULL AFTER family_id');
            }

            $db->exec(
                'UPDATE travelers
                 SET
                    first_name = CASE
                        WHEN COALESCE(TRIM(first_name), "") <> "" THEN first_name
                        WHEN LOCATE(" ", TRIM(full_name)) > 0 THEN SUBSTRING_INDEX(TRIM(full_name), " ", 1)
                        ELSE TRIM(full_name)
                    END,
                    last_name = CASE
                        WHEN COALESCE(TRIM(last_name), "") <> "" THEN last_name
                        WHEN LOCATE(" ", TRIM(full_name)) > 0 THEN TRIM(SUBSTRING(TRIM(full_name), LOCATE(" ", TRIM(full_name)) + 1))
                        ELSE NULL
                    END,
                    current_residence = CASE
                        WHEN COALESCE(TRIM(current_residence), "") <> "" THEN current_residence
                        ELSE address
                    END,
                    permanent_residence = CASE
                        WHEN COALESCE(TRIM(permanent_residence), "") <> "" THEN permanent_residence
                        ELSE address
                    END
                 WHERE 1 = 1'
            );
        },
    ],
    'down' => [
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

            foreach ([
                'color_tag',
                'family_id',
                'district',
                'village',
                'occupation',
                'current_residence',
                'permanent_residence',
                'last_name',
                'first_name',
            ] as $column) {
                if ($columnCheck('travelers', $column)) {
                    $db->exec('ALTER TABLE travelers DROP COLUMN ' . $column);
                }
            }
        },
    ],
];
