<?php

declare(strict_types=1);

return [
    'up' => [
        static function (\PDO $db): void {
            $tableExistsStatement = $db->query("SHOW TABLES LIKE 'booking_service_refund_details'");
            if (! $tableExistsStatement || $tableExistsStatement->fetchColumn() === false) {
                return;
            }

            $columns = [];
            $columnStatement = $db->query('SHOW COLUMNS FROM booking_service_refund_details');
            if ($columnStatement) {
                foreach ($columnStatement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                    $field = (string) ($row['Field'] ?? '');
                    if ($field !== '') {
                        $columns[$field] = true;
                    }
                }
            }

            if (! isset($columns['refund_currency'])) {
                $db->exec('ALTER TABLE booking_service_refund_details ADD COLUMN refund_currency CHAR(3) NULL AFTER refund_payment_method');
            }
            if (! isset($columns['refund_source_amount'])) {
                $db->exec('ALTER TABLE booking_service_refund_details ADD COLUMN refund_source_amount DECIMAL(18,2) NULL AFTER refund_currency');
            }
            if (! isset($columns['refund_exchange_rate_to_booking'])) {
                $db->exec('ALTER TABLE booking_service_refund_details ADD COLUMN refund_exchange_rate_to_booking DECIMAL(18,8) NULL AFTER refund_source_amount');
            }

            $db->exec(
                'UPDATE booking_service_refund_details rd
                 INNER JOIN booking_service_events bse ON bse.id = rd.service_event_id
                 SET rd.refund_currency = COALESCE(NULLIF(rd.refund_currency, ""), bse.currency),
                     rd.refund_source_amount = CASE
                        WHEN rd.refund_source_amount IS NOT NULL THEN rd.refund_source_amount
                        WHEN COALESCE(bse.customer_refund_amount, 0) > 0.005 THEN bse.customer_refund_amount
                        WHEN COALESCE(bse.supplier_refund_amount, 0) > 0.005 THEN bse.supplier_refund_amount
                        ELSE 0
                     END,
                     rd.refund_exchange_rate_to_booking = CASE
                        WHEN rd.refund_exchange_rate_to_booking IS NOT NULL THEN rd.refund_exchange_rate_to_booking
                        ELSE 1.00000000
                     END'
            );
        },
    ],
    'down' => [
        static function (\PDO $db): void {
            $tableExistsStatement = $db->query("SHOW TABLES LIKE 'booking_service_refund_details'");
            if (! $tableExistsStatement || $tableExistsStatement->fetchColumn() === false) {
                return;
            }

            foreach (['refund_exchange_rate_to_booking', 'refund_source_amount', 'refund_currency'] as $columnName) {
                try {
                    $db->exec('ALTER TABLE booking_service_refund_details DROP COLUMN ' . $columnName);
                } catch (\Throwable $exception) {
                }
            }
        },
    ],
];
