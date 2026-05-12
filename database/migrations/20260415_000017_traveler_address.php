<?php

declare(strict_types=1);

return [
    'up' => [
        'ALTER TABLE travelers
            ADD COLUMN address VARCHAR(255) NULL AFTER mobile',
    ],
    'down' => [
        'ALTER TABLE travelers DROP COLUMN address',
    ],
];
