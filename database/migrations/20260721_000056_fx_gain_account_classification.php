<?php

declare(strict_types=1);

return [
    'up' => [
        static function (\PDO $db): void {
            $db->exec(
                'INSERT INTO chart_of_accounts (code, name, account_type, normal_balance, is_system, is_active)
                 VALUES ("FX_GAIN", "Foreign Exchange Gain", "revenue", "credit", 1, 1)
                 ON DUPLICATE KEY UPDATE
                    name = VALUES(name),
                    account_type = VALUES(account_type),
                    normal_balance = VALUES(normal_balance),
                    is_system = 1,
                    is_active = 1'
            );
        },
    ],
    'down' => [],
];
