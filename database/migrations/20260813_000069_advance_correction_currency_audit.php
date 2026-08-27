<?php

declare(strict_types=1);

return [
    'up' => [
        static function (PDO $db): void {
            $customerColumns = [
                'old_currency' => "ALTER TABLE customer_advance_corrections ADD COLUMN old_currency CHAR(3) NULL AFTER currency",
                'new_currency' => "ALTER TABLE customer_advance_corrections ADD COLUMN new_currency CHAR(3) NULL AFTER old_currency",
            ];

            foreach ($customerColumns as $column => $sql) {
                $statement = $db->prepare(
                    "SELECT COUNT(*)
                     FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE()
                       AND TABLE_NAME = 'customer_advance_corrections'
                       AND COLUMN_NAME = :column_name"
                );
                $statement->execute(['column_name' => $column]);
                if ((int) $statement->fetchColumn() === 0) {
                    $db->exec($sql);
                }
            }

            $db->exec(
                "UPDATE customer_advance_corrections
                 SET old_currency = COALESCE(NULLIF(old_currency, ''), currency),
                     new_currency = COALESCE(NULLIF(new_currency, ''), currency)"
            );

            $supplierColumns = [
                'old_currency' => "ALTER TABLE supplier_advance_corrections ADD COLUMN old_currency CHAR(3) NULL AFTER new_payment_date",
                'new_currency' => "ALTER TABLE supplier_advance_corrections ADD COLUMN new_currency CHAR(3) NULL AFTER old_currency",
            ];

            foreach ($supplierColumns as $column => $sql) {
                $statement = $db->prepare(
                    "SELECT COUNT(*)
                     FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE()
                       AND TABLE_NAME = 'supplier_advance_corrections'
                       AND COLUMN_NAME = :column_name"
                );
                $statement->execute(['column_name' => $column]);
                if ((int) $statement->fetchColumn() === 0) {
                    $db->exec($sql);
                }
            }

            $db->exec(
                "UPDATE supplier_advance_corrections sac
                 INNER JOIN supplier_advances sa ON sa.id = sac.supplier_advance_id
                 SET sac.old_currency = COALESCE(NULLIF(sac.old_currency, ''), sa.currency),
                     sac.new_currency = COALESCE(NULLIF(sac.new_currency, ''), sa.currency)"
            );
        },
    ],
    'down' => [],
];
