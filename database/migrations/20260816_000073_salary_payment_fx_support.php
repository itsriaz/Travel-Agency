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

            if (! isset($columns['payment_currency'])) {
                $db->exec('ALTER TABLE employee_salary_payments ADD COLUMN payment_currency CHAR(3) NULL AFTER currency');
            }
            if (! isset($columns['source_paid_amount'])) {
                $db->exec('ALTER TABLE employee_salary_payments ADD COLUMN source_paid_amount DECIMAL(18,2) NULL AFTER paid_amount');
            }
            if (! isset($columns['exchange_rate_to_salary'])) {
                $db->exec('ALTER TABLE employee_salary_payments ADD COLUMN exchange_rate_to_salary DECIMAL(18,8) NULL AFTER source_paid_amount');
            }

            $db->exec(
                'UPDATE employee_salary_payments
                 SET payment_currency = COALESCE(NULLIF(payment_currency, ""), currency),
                     source_paid_amount = CASE
                        WHEN source_paid_amount IS NULL THEN paid_amount
                        ELSE source_paid_amount
                     END,
                     exchange_rate_to_salary = CASE
                        WHEN exchange_rate_to_salary IS NULL AND COALESCE(NULLIF(payment_currency, ""), currency) = currency THEN 1.00000000
                        ELSE exchange_rate_to_salary
                     END'
            );
        },
    ],
    'down' => [
        static function (\PDO $db): void {
            $tableExistsStatement = $db->query("SHOW TABLES LIKE 'employee_salary_payments'");
            if (! $tableExistsStatement || $tableExistsStatement->fetchColumn() === false) {
                return;
            }

            foreach (['exchange_rate_to_salary', 'source_paid_amount', 'payment_currency'] as $columnName) {
                try {
                    $db->exec('ALTER TABLE employee_salary_payments DROP COLUMN ' . $columnName);
                } catch (\Throwable $exception) {
                }
            }
        },
    ],
];
