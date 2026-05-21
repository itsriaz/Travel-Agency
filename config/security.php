<?php

declare(strict_types=1);

$envValue = static function (string $key, ?string $default = null): ?string {
    $value = getenv($key);

    if ($value === false) {
        $serverValue = $_SERVER[$key] ?? $_ENV[$key] ?? null;
        $value = is_string($serverValue) ? $serverValue : null;
    }

    if ($value === null) {
        return $default;
    }

    $trimmed = trim($value);

    return $trimmed === '' ? $default : $trimmed;
};

$defaultResetDeliveryMode = static function () use ($envValue): string {
    $configured = strtolower(trim((string) $envValue('PASSWORD_RESET_DELIVERY_MODE', '')));
    if ($configured !== '') {
        return $configured;
    }

    $appEnv = strtolower(trim((string) $envValue('APP_ENV', 'local')));

    return $appEnv === 'production' ? 'disabled' : 'log';
};

$envBool = static function (string $key, bool $default) use ($envValue): bool {
    $value = $envValue($key);

    if ($value === null) {
        return $default;
    }

    return match (strtolower($value)) {
        '1', 'true', 'yes', 'on' => true,
        '0', 'false', 'no', 'off' => false,
        default => $default,
    };
};

$defaultTwoFactorBypass = static function () use ($envValue): bool {
    $appEnv = strtolower(trim((string) $envValue('APP_ENV', 'local')));

    return $appEnv !== 'production';
};

return [
    'password' => [
        'min_length' => 12,
        'max_length' => 128,
    ],
    'two_factor' => [
        'temporarily_disabled' => $envBool('TWO_FACTOR_TEMPORARILY_DISABLED', $defaultTwoFactorBypass()),
    ],
    'session' => [
        'timeout_minutes' => 30,
        'cookie_secure' => $envBool('SESSION_COOKIE_SECURE', strtolower(trim((string) $envValue('APP_ENV', 'local'))) === 'production'),
        'cookie_samesite' => $envValue('SESSION_COOKIE_SAMESITE', 'Lax'),
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
        'delivery_mode' => $defaultResetDeliveryMode(),
        'log_path' => $envValue('PASSWORD_RESET_LOG_PATH', 'storage/logs/password_reset_links.log'),
    ],
    'otp' => [
        'max_attempts' => 3,
        'window_minutes' => 15,
    ],
    'trusted_device' => [
        'cookie_name' => 'travel_ops_trusted_device',
        'max_days' => 7,
        'cookie_secure' => $envBool('TRUSTED_DEVICE_COOKIE_SECURE', strtolower(trim((string) $envValue('APP_ENV', 'local'))) === 'production'),
        'cookie_samesite' => $envValue('TRUSTED_DEVICE_COOKIE_SAMESITE', 'Lax'),
    ],
    'headers' => [
        'enabled' => $envBool('SECURITY_HEADERS_ENABLED', true),
        'hsts_enabled' => $envBool('SECURITY_HSTS_ENABLED', strtolower(trim((string) $envValue('APP_ENV', 'local'))) === 'production'),
        'hsts_max_age' => 31536000,
        'content_security_policy' => $envValue(
            'SECURITY_CONTENT_SECURITY_POLICY',
            "default-src 'self'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'; object-src 'none'; img-src 'self' data: blob:; font-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; connect-src 'self'"
        ),
        'referrer_policy' => $envValue('SECURITY_REFERRER_POLICY', 'strict-origin-when-cross-origin'),
        'permissions_policy' => $envValue('SECURITY_PERMISSIONS_POLICY', 'camera=(), microphone=(), geolocation=(), payment=()'),
    ],
    'health' => [
        'token' => $envValue('HEALTH_CHECK_TOKEN', ''),
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
