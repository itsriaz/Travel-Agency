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

$envBool = static function (string $key, bool $default) use ($envValue): bool {
    $value = $envValue($key);

    if ($value === null) {
        return $default;
    }

    $normalized = strtolower($value);

    return match ($normalized) {
        '1', 'true', 'yes', 'on' => true,
        '0', 'false', 'no', 'off' => false,
        default => $default,
    };
};

$defaultDebug = static function () use ($envValue): bool {
    $appEnv = strtolower(trim((string) $envValue('APP_ENV', 'local')));

    return $appEnv === 'production' ? false : true;
};

return [
    'name' => $envValue('APP_NAME', 'Travel Agency Operations'),
    'env' => $envValue('APP_ENV', 'local'),
    'debug' => $envBool('APP_DEBUG', $defaultDebug()),
    'url' => $envValue('APP_URL', 'http://localhost/Travel-Agency'),
    'key' => $envValue('APP_KEY', 'base64:Wm5uWGQ0blFSbVQ4ME5hL2p3VVRYeG9NcnN0Qk9ud3pPaGRYRE1vV0d6TT0='),
    'timezone' => $envValue('APP_TIMEZONE', 'Asia/Karachi'),
];
