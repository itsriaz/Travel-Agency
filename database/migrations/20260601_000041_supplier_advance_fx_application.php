<?php

declare(strict_types=1);

return [
    'up' => [
        static function (\PDO $db): void {
            $columns = [];
            foreach (($db->query('SHOW COLUMNS FROM supplier_advance_applications')->fetchAll(\PDO::FETCH_ASSOC) ?: []) as $column) {
                $columns[(string) ($column['Field'] ?? '')] = true;
            }

            $addColumn = static function (string $name, string $definition) use ($db, $columns): void {
                if (isset($columns[$name])) {
                    return;
                }

                $db->exec('ALTER TABLE supplier_advance_applications ADD COLUMN ' . $definition);
            };

            $addColumn('application_type', 'application_type VARCHAR(40) NOT NULL DEFAULT "same_currency_auto" AFTER applied_amount');
            $addColumn('advance_currency', 'advance_currency CHAR(3) NULL AFTER application_type');
            $addColumn('advance_amount_consumed', 'advance_amount_consumed DECIMAL(18,2) NULL AFTER advance_currency');
            $addColumn('obligation_currency', 'obligation_currency CHAR(3) NULL AFTER advance_amount_consumed');
            $addColumn('obligation_amount_applied', 'obligation_amount_applied DECIMAL(18,2) NULL AFTER obligation_currency');
            $addColumn('rate_from_currency', 'rate_from_currency CHAR(3) NULL AFTER obligation_amount_applied');
            $addColumn('rate_to_currency', 'rate_to_currency CHAR(3) NULL AFTER rate_from_currency');
            $addColumn('exchange_rate', 'exchange_rate DECIMAL(18,8) NULL AFTER rate_to_currency');
            $addColumn('exchange_rate_effective_date', 'exchange_rate_effective_date DATE NULL AFTER exchange_rate');
            $addColumn('application_note', 'application_note VARCHAR(190) NULL AFTER exchange_rate_effective_date');

            $indexes = [];
            foreach (($db->query('SHOW INDEX FROM supplier_advance_applications')->fetchAll(\PDO::FETCH_ASSOC) ?: []) as $indexRow) {
                $indexes[(string) ($indexRow['Key_name'] ?? '')] = true;
            }

            if (! isset($indexes['idx_supplier_advance_applications_type'])) {
                $db->exec('CREATE INDEX idx_supplier_advance_applications_type ON supplier_advance_applications (application_type)');
            }
            if (! isset($indexes['idx_supplier_advance_applications_fx_rate_date'])) {
                $db->exec('CREATE INDEX idx_supplier_advance_applications_fx_rate_date ON supplier_advance_applications (exchange_rate_effective_date)');
            }

            $db->exec(
                'UPDATE supplier_advance_applications aa
                 INNER JOIN supplier_advances a ON a.id = aa.supplier_advance_id
                 INNER JOIN supplier_obligations o ON o.id = aa.supplier_obligation_id
                 SET
                    aa.advance_currency = COALESCE(aa.advance_currency, a.currency),
                    aa.advance_amount_consumed = COALESCE(aa.advance_amount_consumed, aa.applied_amount),
                    aa.obligation_currency = COALESCE(aa.obligation_currency, o.currency),
                    aa.obligation_amount_applied = COALESCE(aa.obligation_amount_applied, aa.applied_amount),
                    aa.rate_from_currency = COALESCE(aa.rate_from_currency, o.currency),
                    aa.rate_to_currency = COALESCE(aa.rate_to_currency, a.currency),
                    aa.exchange_rate = COALESCE(aa.exchange_rate, 1.00000000),
                    aa.exchange_rate_effective_date = COALESCE(aa.exchange_rate_effective_date, DATE(aa.created_at)),
                    aa.application_type = CASE
                        WHEN a.currency = o.currency THEN aa.application_type
                        ELSE "cross_currency_review"
                    END'
            );
        },
    ],
    'down' => [
        static function (\PDO $db): void {
            $indexes = [];
            foreach (($db->query('SHOW INDEX FROM supplier_advance_applications')->fetchAll(\PDO::FETCH_ASSOC) ?: []) as $indexRow) {
                $indexes[(string) ($indexRow['Key_name'] ?? '')] = true;
            }

            foreach ([
                'idx_supplier_advance_applications_fx_rate_date',
                'idx_supplier_advance_applications_type',
            ] as $indexName) {
                if (isset($indexes[$indexName])) {
                    $db->exec('ALTER TABLE supplier_advance_applications DROP INDEX ' . $indexName);
                }
            }

            $columns = [];
            foreach (($db->query('SHOW COLUMNS FROM supplier_advance_applications')->fetchAll(\PDO::FETCH_ASSOC) ?: []) as $column) {
                $columns[(string) ($column['Field'] ?? '')] = true;
            }

            foreach ([
                'application_note',
                'exchange_rate_effective_date',
                'exchange_rate',
                'rate_to_currency',
                'rate_from_currency',
                'obligation_amount_applied',
                'obligation_currency',
                'advance_amount_consumed',
                'advance_currency',
                'application_type',
            ] as $columnName) {
                if (isset($columns[$columnName])) {
                    $db->exec('ALTER TABLE supplier_advance_applications DROP COLUMN ' . $columnName);
                }
            }
        },
    ],
];
