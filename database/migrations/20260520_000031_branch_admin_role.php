<?php

declare(strict_types=1);

return [
    'up' => [
        "INSERT INTO roles (code, name)
         VALUES ('branch_admin', 'Branch Admin')
         ON DUPLICATE KEY UPDATE name = VALUES(name)",
    ],
    'down' => [],
];
