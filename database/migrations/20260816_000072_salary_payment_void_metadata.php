<?php

declare(strict_types=1);

return [
    'up' => [
        static function (\PDO $db): void {
            $tableExistsStatement = $db->query("SHOW TABLES LIKE 'employee_salary_payments'");
            if (! $tableExistsStatement || $tableExistsStatement->fetchColumn() === false) {
                return;
            }

            $columns = [];
            $columnStatement = $db->query('SHOW COLUMNS FROM employee_salary_payments');
            if ($columnStatement) {
                foreach ($columnStatement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                    $field = (string) ($row['Field'] ?? '');
                    if ($field !== '') {
                        $columns[$field] = true;
                    }
                }
            }

            $indexes = [];
            $indexStatement = $db->query('SHOW INDEX FROM employee_salary_payments');
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
                   AND TABLE_NAME = 'employee_salary_payments'
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

            if (! isset($columns['status'])) {
                $db->exec('ALTER TABLE employee_salary_payments ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT "posted" AFTER remarks');
            }
            if (! isset($columns['void_reason'])) {
                $db->exec('ALTER TABLE employee_salary_payments ADD COLUMN void_reason VARCHAR(500) NULL AFTER status');
            }
            if (! isset($columns['voided_by_user_id'])) {
                $db->exec('ALTER TABLE employee_salary_payments ADD COLUMN voided_by_user_id INT UNSIGNED NULL AFTER void_reason');
            }
            if (! isset($columns['voided_at'])) {
                $db->exec('ALTER TABLE employee_salary_payments ADD COLUMN voided_at DATETIME NULL AFTER voided_by_user_id');
            }

            $db->exec('UPDATE employee_salary_payments SET status = "posted" WHERE status IS NULL OR TRIM(status) = ""');

            if (! isset($indexes['idx_employee_salary_payments_status'])) {
                $db->exec('ALTER TABLE employee_salary_payments ADD INDEX idx_employee_salary_payments_status (status)');
            }
            if (! isset($indexes['idx_employee_salary_payments_voided_by'])) {
                $db->exec('ALTER TABLE employee_salary_payments ADD INDEX idx_employee_salary_payments_voided_by (voided_by_user_id)');
            }
            if (! isset($indexes['idx_employee_salary_payments_voided_at'])) {
                $db->exec('ALTER TABLE employee_salary_payments ADD INDEX idx_employee_salary_payments_voided_at (voided_at)');
            }

            if (! isset($foreignKeys['fk_emp_salary_payment_voided_by'])) {
                $db->exec(
                    'ALTER TABLE employee_salary_payments
                     ADD CONSTRAINT fk_emp_salary_payment_voided_by
                     FOREIGN KEY (voided_by_user_id) REFERENCES users (id) ON DELETE SET NULL'
                );
            }
        },
    ],
    'down' => [
        static function (\PDO $db): void {
            $tableExistsStatement = $db->query("SHOW TABLES LIKE 'employee_salary_payments'");
            if (! $tableExistsStatement || $tableExistsStatement->fetchColumn() === false) {
                return;
            }

            $foreignKeys = [];
            $fkStatement = $db->query(
                "SELECT CONSTRAINT_NAME
                 FROM information_schema.TABLE_CONSTRAINTS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'employee_salary_payments'
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

            if (isset($foreignKeys['fk_emp_salary_payment_voided_by'])) {
                $db->exec('ALTER TABLE employee_salary_payments DROP FOREIGN KEY fk_emp_salary_payment_voided_by');
            }

            foreach ([
                'idx_employee_salary_payments_voided_at',
                'idx_employee_salary_payments_voided_by',
                'idx_employee_salary_payments_status',
            ] as $indexName) {
                try {
                    $db->exec('ALTER TABLE employee_salary_payments DROP INDEX ' . $indexName);
                } catch (\Throwable $exception) {
                }
            }

            foreach (['voided_at', 'voided_by_user_id', 'void_reason', 'status'] as $columnName) {
                try {
                    $db->exec('ALTER TABLE employee_salary_payments DROP COLUMN ' . $columnName);
                } catch (\Throwable $exception) {
                }
            }
        },
    ],
];
