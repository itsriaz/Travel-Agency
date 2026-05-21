<?php

declare(strict_types=1);

$envValue = static function (string $key, string $default = ''): string {
    $value = getenv($key);

    if ($value === false) {
        $serverValue = $_SERVER[$key] ?? $_ENV[$key] ?? null;
        $value = is_string($serverValue) ? $serverValue : null;
    }

    if ($value === null) {
        return $default;
    }

    return trim($value);
};

return [
    'host' => $envValue('DB_HOST', '127.0.0.1'),
    'port' => $envValue('DB_PORT', '3306'),
    'database' => $envValue('DB_DATABASE', 'travel_agency_ops'),
    'username' => $envValue('DB_USERNAME', 'root'),
    'password' => $envValue('DB_PASSWORD', ''),
    'charset' => $envValue('DB_CHARSET', 'utf8mb4'),
];
