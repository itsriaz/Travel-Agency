<?php

declare(strict_types=1);

return [
    'up' => [
        static function (PDO $db): void {
            $columnExists = static function (string $column) use ($db): bool {
                $statement = $db->prepare(
                    "SELECT COUNT(*)
                     FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE()
                       AND TABLE_NAME = 'service_financial_corrections'
                       AND COLUMN_NAME = :column"
                );
                $statement->execute(['column' => $column]);

                return (int) $statement->fetchColumn() > 0;
            };

            $addColumn = static function (string $column, string $definition, string $after) use ($db, $columnExists): void {
                if ($columnExists($column)) {
                    return;
                }

                $db->exec(
                    "ALTER TABLE service_financial_corrections
                     ADD COLUMN {$column} {$definition} AFTER {$after}"
                );
            };

            $addColumn('prior_invoice_currency', "CHAR(3) NOT NULL DEFAULT 'PKR'", 'correction_note');
            $addColumn('new_invoice_currency', "CHAR(3) NOT NULL DEFAULT 'PKR'", 'prior_invoice_currency');
            $addColumn('prior_cost_currency', "CHAR(3) NOT NULL DEFAULT 'PKR'", 'new_invoice_currency');
            $addColumn('new_cost_currency', "CHAR(3) NOT NULL DEFAULT 'PKR'", 'prior_cost_currency');
            $addColumn('prior_pricing_exchange_rate', 'DECIMAL(18,8) NOT NULL DEFAULT 1.00000000', 'new_cost_currency');
            $addColumn('new_pricing_exchange_rate', 'DECIMAL(18,8) NOT NULL DEFAULT 1.00000000', 'prior_pricing_exchange_rate');
            $addColumn('prior_pricing_rate_effective_date', 'DATE NULL', 'new_pricing_exchange_rate');
            $addColumn('new_pricing_rate_effective_date', 'DATE NULL', 'prior_pricing_rate_effective_date');
        },
    ],
    'down' => [
        static function (PDO $db): void {
            $columnExists = static function (string $column) use ($db): bool {
                $statement = $db->prepare(
                    "SELECT COUNT(*)
                     FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE()
                       AND TABLE_NAME = 'service_financial_corrections'
                       AND COLUMN_NAME = :column"
                );
                $statement->execute(['column' => $column]);

                return (int) $statement->fetchColumn() > 0;
            };

            foreach ([
                'new_pricing_rate_effective_date',
                'prior_pricing_rate_effective_date',
                'new_pricing_exchange_rate',
                'prior_pricing_exchange_rate',
                'new_cost_currency',
                'prior_cost_currency',
                'new_invoice_currency',
                'prior_invoice_currency',
            ] as $column) {
                if ($columnExists($column)) {
                    $db->exec("ALTER TABLE service_financial_corrections DROP COLUMN {$column}");
                }
            }
        },
    ],
];
