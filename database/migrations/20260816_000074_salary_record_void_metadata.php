<?php

declare(strict_types=1);

return [
    'up' => [
        static function (\PDO $db): void {
            $tableExistsStatement = $db->query("SHOW TABLES LIKE 'employee_salary_records'");
            if (! $tableExistsStatement || $tableExistsStatement->fetchColumn() === false) {
                return;
            }

            $columns = [];
            $columnStatement = $db->query('SHOW COLUMNS FROM employee_salary_records');
            if ($columnStatement) {
                foreach ($columnStatement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                    $field = (string) ($row['Field'] ?? '');
                    if ($field !== '') {
                        $columns[$field] = true;
                    }
                }
            }

            $indexes = [];
            $indexStatement = $db->query('SHOW INDEX FROM employee_salary_records');
            if ($indexStatement) {
                foreach ($indexStatement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                    $keyName = (string) ($row['Key_name'] ?? '');
                    if ($keyName !== '') {
                        $indexes[$keyName] = true;
                    }
                }
            }

            $foreignKeys = [];
            $fkStatement = $db->query(
                "SELECT CONSTRAINT_NAME
                 FROM information_schema.TABLE_CONSTRAINTS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'employee_salary_records'
                   AND CONSTRAINT_TYPE = 'FOREIGN KEY'"
            );
            if ($fkStatement) {
                foreach ($fkStatement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                    $constraintName = (string) ($row['CONSTRAINT_NAME'] ?? '');
                    if ($constraintName !== '') {
                        $foreignKeys[$constraintName] = true;
                    }
                }
            }

            if (! isset($columns['void_reason'])) {
                $db->exec('ALTER TABLE employee_salary_records ADD COLUMN void_reason VARCHAR(500) NULL AFTER status');
            }
            if (! isset($columns['voided_by_user_id'])) {
                $db->exec('ALTER TABLE employee_salary_records ADD COLUMN voided_by_user_id INT UNSIGNED NULL AFTER void_reason');
            }
            if (! isset($columns['voided_at'])) {
                $db->exec('ALTER TABLE employee_salary_records ADD COLUMN voided_at DATETIME NULL AFTER voided_by_user_id');
            }

            if (! isset($indexes['idx_employee_salary_voided_by'])) {
                $db->exec('ALTER TABLE employee_salary_records ADD INDEX idx_employee_salary_voided_by (voided_by_user_id)');
            }
            if (! isset($indexes['idx_employee_salary_voided_at'])) {
                $db->exec('ALTER TABLE employee_salary_records ADD INDEX idx_employee_salary_voided_at (voided_at)');
            }

            if (! isset($foreignKeys['fk_employee_salary_voided_by'])) {
                $db->exec(
                    'ALTER TABLE employee_salary_records
                     ADD CONSTRAINT fk_employee_salary_voided_by
                     FOREIGN KEY (voided_by_user_id) REFERENCES users (id) ON DELETE SET NULL'
                );
            }
        },
    ],
    'down' => [
        static function (\PDO $db): void {
            $tableExistsStatement = $db->query("SHOW TABLES LIKE 'employee_salary_records'");
            if (! $tableExistsStatement || $tableExistsStatement->fetchColumn() === false) {
                return;
            }

            $foreignKeys = [];
            $fkStatement = $db->query(
                "SELECT CONSTRAINT_NAME
                 FROM information_schema.TABLE_CONSTRAINTS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'employee_salary_records'
                   AND CONSTRAINT_TYPE = 'FOREIGN KEY'"
            );
            if ($fkStatement) {
                foreach ($fkStatement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                    $constraintName = (string) ($row['CONSTRAINT_NAME'] ?? '');
                    if ($constraintName !== '') {
                        $foreignKeys[$constraintName] = true;
                    }
                }
            }

            if (isset($foreignKeys['fk_employee_salary_voided_by'])) {
                $db->exec('ALTER TABLE employee_salary_records DROP FOREIGN KEY fk_employee_salary_voided_by');
            }

            foreach ([
                'idx_employee_salary_voided_at',
                'idx_employee_salary_voided_by',
            ] as $indexName) {
                try {
                    $db->exec('ALTER TABLE employee_salary_records DROP INDEX ' . $indexName);
                } catch (\Throwable $exception) {
                }
            }

            foreach (['voided_at', 'voided_by_user_id', 'void_reason'] as $columnName) {
                try {
                    $db->exec('ALTER TABLE employee_salary_records DROP COLUMN ' . $columnName);
                } catch (\Throwable $exception) {
                }
            }
        },
    ],
];
