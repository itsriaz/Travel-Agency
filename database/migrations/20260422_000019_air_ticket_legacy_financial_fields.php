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

            $columns = [
                'other_fare' => 'DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER taxes',
                'soto_fare' => 'DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER other_fare',
                'spyi_amount' => 'DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER soto_fare',
                'aq_yr_pk_amount' => 'DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER spyi_amount',
                'yq_amount' => 'DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER aq_yr_pk_amount',
                'oth_amount' => 'DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER yq_amount',
                'vat_input' => 'DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER oth_amount',
                'discount_amount' => 'DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER service_charge',
            ];

            foreach ($columns as $column => $definition) {
                if (! $columnExists($column)) {
                    $db->exec('ALTER TABLE booking_services ADD COLUMN ' . $column . ' ' . $definition);
                }
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

            foreach (['discount_amount', 'vat_input', 'oth_amount', 'yq_amount', 'aq_yr_pk_amount', 'spyi_amount', 'soto_fare', 'other_fare'] as $column) {
                if ($columnExists($column)) {
                    $db->exec('ALTER TABLE booking_services DROP COLUMN ' . $column);
                }
            }
        },
    ],
];
