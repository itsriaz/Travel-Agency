<?php

declare(strict_types=1);

return [
    'up' => [
        static function (PDO $db): void {
            $columns = [
                'old_supplier_id' => 'ADD COLUMN old_supplier_id INT UNSIGNED NULL AFTER reason',
                'new_supplier_id' => 'ADD COLUMN new_supplier_id INT UNSIGNED NULL AFTER old_supplier_id',
            ];

            foreach ($columns as $column => $definition) {
                $statement = $db->prepare(
                    "SELECT COUNT(*)
                     FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE()
                       AND TABLE_NAME = 'supplier_advance_corrections'
                       AND COLUMN_NAME = :column_name"
                );
                $statement->execute(['column_name' => $column]);
                if ((int) $statement->fetchColumn() === 0) {
                    $db->exec('ALTER TABLE supplier_advance_corrections ' . $definition);
                }
            }

            $db->exec(
                'ALTER TABLE supplier_advance_corrections
                 MODIFY COLUMN old_supplier_id INT UNSIGNED NULL,
                 MODIFY COLUMN new_supplier_id INT UNSIGNED NULL'
            );

            $foreignKeys = [
                'fk_sac_old_supplier' => 'FOREIGN KEY (old_supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL',
                'fk_sac_new_supplier' => 'FOREIGN KEY (new_supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL',
            ];

            foreach ($foreignKeys as $constraint => $definition) {
                $statement = $db->prepare(
                    "SELECT COUNT(*)
                     FROM information_schema.TABLE_CONSTRAINTS
                     WHERE CONSTRAINT_SCHEMA = DATABASE()
                       AND TABLE_NAME = 'supplier_advance_corrections'
                       AND CONSTRAINT_NAME = :constraint_name"
                );
                $statement->execute(['constraint_name' => $constraint]);
                if ((int) $statement->fetchColumn() === 0) {
                    $db->exec(
                        'ALTER TABLE supplier_advance_corrections ADD CONSTRAINT '
                        . $constraint . ' ' . $definition
                    );
                }
            }
        },
    ],
    'down' => [],
];
