<?php

declare(strict_types=1);

return [
    'up' => [
        'ALTER TABLE suppliers
            ADD COLUMN contact_person VARCHAR(190) NULL AFTER default_currency,
            ADD COLUMN phone VARCHAR(50) NULL AFTER contact_person,
            ADD COLUMN email VARCHAR(190) NULL AFTER phone,
            ADD COLUMN address VARCHAR(500) NULL AFTER email',
    ],
    'down' => [
        'ALTER TABLE suppliers
            DROP COLUMN address,
            DROP COLUMN email,
            DROP COLUMN phone,
            DROP COLUMN contact_person',
    ],
];

