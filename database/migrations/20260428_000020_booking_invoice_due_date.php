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
                    'table_name' => 'bookings',
                    'column_name' => $column,
                ]);

                return (int) $statement->fetchColumn() > 0;
            };

            if (! $columnExists('due_date')) {
                $db->exec('ALTER TABLE bookings ADD COLUMN due_date DATE NULL AFTER booking_date');
            }

            $db->exec(
                'UPDATE bookings b
                 INNER JOIN (
                    SELECT booking_reference, MIN(due_date) AS earliest_due_date
                    FROM customer_receivable_items
                    WHERE due_date IS NOT NULL
                    GROUP BY booking_reference
                 ) cri ON cri.booking_reference = b.booking_reference
                 SET b.due_date = cri.earliest_due_date
                 WHERE b.due_date IS NULL'
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
                'table_name' => 'bookings',
                'column_name' => 'due_date',
            ]);

            if ((int) $statement->fetchColumn() > 0) {
                $db->exec('ALTER TABLE bookings DROP COLUMN due_date');
            }
        },
    ],
];
