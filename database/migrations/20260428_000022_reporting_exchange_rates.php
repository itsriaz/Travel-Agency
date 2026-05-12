<?php

declare(strict_types=1);

return [
    'up' => [
        static function (\PDO $db): void {
            $db->exec(
                'CREATE TABLE IF NOT EXISTS exchange_rates (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    from_currency VARCHAR(10) NOT NULL,
                    to_currency VARCHAR(10) NOT NULL,
                    rate_value DECIMAL(18,8) NOT NULL,
                    effective_date DATE NOT NULL,
                    rate_source VARCHAR(100) NULL,
                    notes TEXT NULL,
                    is_active TINYINT(1) NOT NULL DEFAULT 1,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY uniq_exchange_rate_effective (from_currency, to_currency, effective_date),
                    KEY idx_exchange_rates_lookup (to_currency, from_currency, effective_date, is_active)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );

            $seedStatement = $db->prepare(
                'INSERT INTO exchange_rates (
                    from_currency, to_currency, rate_value, effective_date, rate_source, notes, is_active
                 ) VALUES (
                    :from_currency, :to_currency, :rate_value, :effective_date, :rate_source, :notes, 1
                 )
                 ON DUPLICATE KEY UPDATE
                    rate_value = VALUES(rate_value),
                    rate_source = VALUES(rate_source),
                    notes = VALUES(notes),
                    is_active = VALUES(is_active)'
            );

            foreach ([
                [
                    'from_currency' => 'PKR',
                    'to_currency' => 'PKR',
                    'rate_value' => 1.00000000,
                    'effective_date' => '2026-01-01',
                    'rate_source' => 'system_seed',
                    'notes' => 'Native reporting currency baseline.',
                ],
                [
                    'from_currency' => 'AED',
                    'to_currency' => 'PKR',
                    'rate_value' => 77.00000000,
                    'effective_date' => '2026-01-01',
                    'rate_source' => 'system_seed',
                    'notes' => 'Version-1 opening AED to PKR reporting rate. Update through setup when needed.',
                ],
            ] as $seedRow) {
                $seedStatement->execute($seedRow);
            }
        },
    ],
    'down' => [
        'DROP TABLE IF EXISTS exchange_rates',
    ],
];
