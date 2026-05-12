<?php

declare(strict_types=1);

return [
    'up' => [
        'ALTER TABLE booking_services
            ADD COLUMN loss_reason TEXT NULL AFTER remarks',
        'ALTER TABLE booking_services
            ADD COLUMN loss_reason_recorded_at DATETIME NULL AFTER loss_reason',
    ],
    'down' => [
        'ALTER TABLE booking_services DROP COLUMN loss_reason_recorded_at',
        'ALTER TABLE booking_services DROP COLUMN loss_reason',
    ],
];
