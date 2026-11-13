<?php

declare(strict_types=1);

return [
    'up' => [
        'INSERT INTO chart_of_accounts
            (code, name, purpose, account_type, normal_balance, is_system, is_active)
         VALUES
            ("PRIOR_PERIOD_SUPPLIER_ADJUSTMENT", "Prior-Period Supplier Adjustment",
             "Historical supplier balance corrections outside current-period service profit",
             "equity", "credit", 1, 1)
         ON DUPLICATE KEY UPDATE code = code',
    ],
    'down' => [],
];
