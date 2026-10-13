<?php

declare(strict_types=1);

return [
    'up' => [
        'ALTER TABLE customer_receipts
         ADD COLUMN linked_payer_role VARCHAR(20) NULL AFTER receipt_purpose',
    ],
    'down' => [],
];
