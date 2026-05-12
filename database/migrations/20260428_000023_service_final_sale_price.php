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

            if (! $columnExists('final_sale_price')) {
                $db->exec('ALTER TABLE booking_services ADD COLUMN final_sale_price DECIMAL(18,2) NULL AFTER discount_amount');
            }

            if (! $columnExists('net_profit_loss')) {
                $db->exec('ALTER TABLE booking_services ADD COLUMN net_profit_loss DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER final_sale_price');
            }

            $db->exec(
                "UPDATE booking_services
                 SET final_sale_price = CASE
                        WHEN service_type = 'air ticket'
                            THEN ROUND(COALESCE(purchase_cost, 0) + COALESCE(service_charge, 0) - COALESCE(discount_amount, 0), 2)
                        ELSE ROUND(COALESCE(sale_price, 0) + COALESCE(service_charge, 0) - COALESCE(discount_amount, 0), 2)
                    END
                 WHERE final_sale_price IS NULL"
            );

            $db->exec(
                'UPDATE booking_services
                 SET net_profit_loss = ROUND(COALESCE(final_sale_price, 0) - COALESCE(purchase_cost, 0), 2)'
            );
        },
    ],
    'down' => [
        static function (\PDO $db): void {
            $statement = $db->prepare(
                'SELECT COUNT(*)
                 FROM information_schema.columns
                 WHERE table_schema = DATABASE()
                   AND table_name = :table_name
                   AND column_name = :column_name'
            );

            $statement->execute([
                'table_name' => 'booking_services',
                'column_name' => 'net_profit_loss',
            ]);
            if ((int) $statement->fetchColumn() > 0) {
                $db->exec('ALTER TABLE booking_services DROP COLUMN net_profit_loss');
            }

            $statement->execute([
                'table_name' => 'booking_services',
                'column_name' => 'final_sale_price',
            ]);
            if ((int) $statement->fetchColumn() > 0) {
                $db->exec('ALTER TABLE booking_services DROP COLUMN final_sale_price');
            }
        },
    ],
];
