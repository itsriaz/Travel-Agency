<?php

declare(strict_types=1);

return [
    'password' => [
        'min_length' => 12,
        'max_length' => 128,
    ],
    'two_factor' => [
        // Temporary development bypass. Set to false to re-enable 2FA enforcement.
        'temporarily_disabled' => true,
    ],
    'session' => [
        'timeout_minutes' => 30,
    ],
    'login' => [
        'max_attempts' => 3,
        'window_minutes' => 15,
    ],
    'forgot' => [
        'max_attempts' => 3,
        'window_minutes' => 30,
    ],
    'reset' => [
        'max_attempts' => 3,
        'window_minutes' => 30,
        'token_ttl_minutes' => 60,
    ],
    'otp' => [
        'max_attempts' => 3,
        'window_minutes' => 15,
    ],
    'trusted_device' => [
        'cookie_name' => 'travel_ops_trusted_device',
        'max_days' => 7,
    ],
    'documents' => [
        'max_upload_bytes' => 8 * 1024 * 1024,
        'allowed_extensions' => ['pdf', 'jpg', 'jpeg', 'png', 'webp'],
        'allowed_mime_types' => [
            'application/pdf',
            'image/jpeg',
            'image/png',
            'image/webp',
        ],
    ],
];
