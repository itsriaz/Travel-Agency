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

            if (! $columnExists('cost_currency')) {
                $db->exec(
                    "ALTER TABLE booking_services
                     ADD COLUMN cost_currency CHAR(3) NULL AFTER currency"
                );
            }

            if (! $columnExists('pricing_exchange_rate')) {
                $db->exec(
                    "ALTER TABLE booking_services
                     ADD COLUMN pricing_exchange_rate DECIMAL(18,8) NOT NULL DEFAULT 1.00000000 AFTER purchase_cost"
                );
            }

            if (! $columnExists('pricing_rate_effective_date')) {
                $db->exec(
                    "ALTER TABLE booking_services
                     ADD COLUMN pricing_rate_effective_date DATE NULL AFTER pricing_exchange_rate"
                );
            }
        },
        static function (PDO $db): void {
            $db->exec(
                "UPDATE booking_services
                 SET cost_currency = COALESCE(NULLIF(cost_currency, ''), currency)"
            );

            $db->exec(
                "UPDATE booking_services
                 SET pricing_exchange_rate = CASE
                        WHEN pricing_exchange_rate IS NULL OR pricing_exchange_rate <= 0 THEN 1.00000000
                        ELSE pricing_exchange_rate
                    END"
            );

            $db->exec(
                "UPDATE booking_services
                 SET pricing_rate_effective_date = COALESCE(pricing_rate_effective_date, due_date, CURDATE())"
            );

            $db->exec(
                "ALTER TABLE booking_services
                 MODIFY cost_currency CHAR(3) NOT NULL"
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

            if ($columnExists('pricing_rate_effective_date')) {
                $db->exec('ALTER TABLE booking_services DROP COLUMN pricing_rate_effective_date');
            }

            if ($columnExists('pricing_exchange_rate')) {
                $db->exec('ALTER TABLE booking_services DROP COLUMN pricing_exchange_rate');
            }

            if ($columnExists('cost_currency')) {
                $db->exec('ALTER TABLE booking_services DROP COLUMN cost_currency');
            }
        },
    ],
];
