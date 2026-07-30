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
                       AND TABLE_NAME = 'booking_services'
                       AND COLUMN_NAME = :column"
                );
                $statement->execute(['column' => $column]);

                return (int) $statement->fetchColumn() > 0;
            };

            if (! $columnExists('service_charge_currency')) {
                $db->exec(
                    "ALTER TABLE booking_services
                     ADD COLUMN service_charge_currency CHAR(3) NULL AFTER service_charge"
                );
            }
            if (! $columnExists('service_charge_exchange_rate')) {
                $db->exec(
                    "ALTER TABLE booking_services
                     ADD COLUMN service_charge_exchange_rate DECIMAL(18,8) NOT NULL DEFAULT 1.00000000 AFTER service_charge_currency"
                );
            }
            if (! $columnExists('service_charge_rate_effective_date')) {
                $db->exec(
                    "ALTER TABLE booking_services
                     ADD COLUMN service_charge_rate_effective_date DATE NULL AFTER service_charge_exchange_rate"
                );
            }
        },
        static function (PDO $db): void {
            // Preserve the historical calculation exactly: air-ticket agency components were
            // previously entered in cost currency; other services used invoice currency.
            $db->exec(
                "UPDATE booking_services
                 SET service_charge_currency = CASE
                        WHEN LOWER(TRIM(service_type)) IN ('air ticket', 'air_ticket', 'air')
                            THEN COALESCE(NULLIF(cost_currency, ''), currency)
                        ELSE currency
                     END
                 WHERE service_charge_currency IS NULL OR service_charge_currency = ''"
            );
            $db->exec(
                "UPDATE booking_services
                 SET service_charge_exchange_rate = CASE
                        WHEN service_charge_currency = currency THEN 1.00000000
                        WHEN LOWER(TRIM(service_type)) IN ('air ticket', 'air_ticket', 'air')
                            THEN COALESCE(NULLIF(pricing_exchange_rate, 0), 1.00000000)
                        ELSE 1.00000000
                     END,
                     service_charge_rate_effective_date = COALESCE(
                        service_charge_rate_effective_date,
                        pricing_rate_effective_date,
                        due_date,
                        CURDATE()
                     )"
            );
            $db->exec(
                "ALTER TABLE booking_services
                 MODIFY service_charge_currency CHAR(3) NOT NULL"
            );

            $correctionColumnExists = static function (string $column) use ($db): bool {
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
            $addCorrectionColumn = static function (string $column, string $definition, string $after) use ($db, $correctionColumnExists): void {
                if (! $correctionColumnExists($column)) {
                    $db->exec("ALTER TABLE service_financial_corrections ADD COLUMN {$column} {$definition} AFTER {$after}");
                }
            };
            $addCorrectionColumn('prior_service_charge_currency', "CHAR(3) NOT NULL DEFAULT 'PKR'", 'new_pricing_rate_effective_date');
            $addCorrectionColumn('new_service_charge_currency', "CHAR(3) NOT NULL DEFAULT 'PKR'", 'prior_service_charge_currency');
            $addCorrectionColumn('prior_service_charge_exchange_rate', 'DECIMAL(18,8) NOT NULL DEFAULT 1.00000000', 'new_service_charge_currency');
            $addCorrectionColumn('new_service_charge_exchange_rate', 'DECIMAL(18,8) NOT NULL DEFAULT 1.00000000', 'prior_service_charge_exchange_rate');
            $addCorrectionColumn('prior_service_charge_rate_effective_date', 'DATE NULL', 'new_service_charge_exchange_rate');
            $addCorrectionColumn('new_service_charge_rate_effective_date', 'DATE NULL', 'prior_service_charge_rate_effective_date');

            // Existing audit rows must mirror the calculation that was in force when they
            // were written, otherwise the historical correction audit would appear to drift.
            $db->exec(
                "UPDATE service_financial_corrections
                 SET prior_service_charge_currency = CASE
                        WHEN LOWER(TRIM(service_type)) IN ('air ticket', 'air_ticket', 'air')
                            THEN COALESCE(NULLIF(prior_cost_currency, ''), prior_invoice_currency)
                        ELSE prior_invoice_currency
                     END,
                     new_service_charge_currency = CASE
                        WHEN LOWER(TRIM(service_type)) IN ('air ticket', 'air_ticket', 'air')
                            THEN COALESCE(NULLIF(new_cost_currency, ''), new_invoice_currency)
                        ELSE new_invoice_currency
                     END,
                     prior_service_charge_exchange_rate = CASE
                        WHEN LOWER(TRIM(service_type)) IN ('air ticket', 'air_ticket', 'air')
                             AND UPPER(TRIM(prior_invoice_currency)) <> UPPER(TRIM(prior_cost_currency))
                            THEN COALESCE(NULLIF(prior_pricing_exchange_rate, 0), 1.00000000)
                        ELSE 1.00000000
                     END,
                     new_service_charge_exchange_rate = CASE
                        WHEN LOWER(TRIM(service_type)) IN ('air ticket', 'air_ticket', 'air')
                             AND UPPER(TRIM(new_invoice_currency)) <> UPPER(TRIM(new_cost_currency))
                            THEN COALESCE(NULLIF(new_pricing_exchange_rate, 0), 1.00000000)
                        ELSE 1.00000000
                     END,
                     prior_service_charge_rate_effective_date = COALESCE(
                        prior_service_charge_rate_effective_date,
                        prior_pricing_rate_effective_date,
                        correction_date
                     ),
                     new_service_charge_rate_effective_date = COALESCE(
                        new_service_charge_rate_effective_date,
                        new_pricing_rate_effective_date,
                        correction_date
                     )"
            );
        },
    ],
    'down' => [
        static function (PDO $db): void {
            $columnExists = static function (string $column) use ($db): bool {
                $statement = $db->prepare(
                    "SELECT COUNT(*)
                     FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE()
                       AND TABLE_NAME = 'booking_services'
                       AND COLUMN_NAME = :column"
                );
                $statement->execute(['column' => $column]);

                return (int) $statement->fetchColumn() > 0;
            };

            foreach (['service_charge_rate_effective_date', 'service_charge_exchange_rate', 'service_charge_currency'] as $column) {
                if ($columnExists($column)) {
                    $db->exec('ALTER TABLE booking_services DROP COLUMN ' . $column);
                }
            }

            foreach ([
                'new_service_charge_rate_effective_date',
                'prior_service_charge_rate_effective_date',
                'new_service_charge_exchange_rate',
                'prior_service_charge_exchange_rate',
                'new_service_charge_currency',
                'prior_service_charge_currency',
            ] as $column) {
                $statement = $db->prepare(
                    "SELECT COUNT(*) FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE()
                       AND TABLE_NAME = 'service_financial_corrections'
                       AND COLUMN_NAME = :column"
                );
                $statement->execute(['column' => $column]);
                if ((int) $statement->fetchColumn() > 0) {
                    $db->exec('ALTER TABLE service_financial_corrections DROP COLUMN ' . $column);
                }
            }
        },
    ],
];
