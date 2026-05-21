<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefixes = [
        'App\\' => BASE_PATH . '/app/',
        'PragmaRX\\Google2FA\\' => BASE_PATH . '/vendor/pragmarx/google2fa/src/',
        'ParagonIE\\ConstantTime\\' => BASE_PATH . '/vendor/paragonie/constant_time_encoding/src/',
        'BaconQrCode\\' => BASE_PATH . '/vendor/bacon/bacon-qr-code/src/',
        'DASPRiD\\Enum\\' => BASE_PATH . '/vendor/dasprid/enum/src/',
    ];

    foreach ($prefixes as $prefix => $baseDir) {
        if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
            continue;
        }

        $relativeClass = substr($class, strlen($prefix));
        $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

        if (is_file($file)) {
            require $file;
        }

        return;
    }
});

/*
 * Load a private .env file for shared hosting or local XAMPP setups.
 * Existing server-level environment variables always win.
 */
$bootstrapLoadEnvFile = static function (string $path): void {
    if (! is_file($path) || ! is_readable($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (! is_array($lines)) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        if (str_starts_with($line, 'export ')) {
            $line = trim(substr($line, 7));
        }

        $separatorPosition = strpos($line, '=');
        if ($separatorPosition === false) {
            continue;
        }

        $key = trim(substr($line, 0, $separatorPosition));
        if ($key === '' || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key) !== 1) {
            continue;
        }

        if (getenv($key) !== false || array_key_exists($key, $_ENV) || array_key_exists($key, $_SERVER)) {
            continue;
        }

        $value = trim(substr($line, $separatorPosition + 1));
        if (
            strlen($value) >= 2
            && (
                ($value[0] === '"' && $value[strlen($value) - 1] === '"')
                || ($value[0] === "'" && $value[strlen($value) - 1] === "'")
            )
        ) {
            $value = substr($value, 1, -1);
        }

        putenv($key . '=' . $value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
};

$bootstrapLoadEnvFile(BASE_PATH . '/.env');

/*
 * Bootstrap runs before App::bootstrap() assigns the global app instance.
 * Keep this early error configuration independent from app()/config().
 */
$bootstrapEnvValue = static function (string $key, ?string $default = null): ?string {
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

$bootstrapEnvBool = static function (string $key, bool $default) use ($bootstrapEnvValue): bool {
    $value = $bootstrapEnvValue($key);

    if ($value === null) {
        return $default;
    }

    return match (strtolower($value)) {
        '1', 'true', 'yes', 'on' => true,
        '0', 'false', 'no', 'off' => false,
        default => $default,
    };
};

$bootstrapAppEnv = strtolower(trim((string) $bootstrapEnvValue('APP_ENV', 'local')));
$bootstrapDebug = $bootstrapEnvBool('APP_DEBUG', $bootstrapAppEnv !== 'production');

ini_set('display_errors', $bootstrapDebug ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', BASE_PATH . '/storage/logs/php-error.log');

set_exception_handler(static function (Throwable $exception): void {
    http_response_code(500);
    app_log_exception($exception, 'app.uncaught_exception');

    if (app_debug_tools_enabled()) {
        echo nl2br(e($exception->getMessage() . PHP_EOL . PHP_EOL . $exception->getTraceAsString()));
        return;
    }

    echo \App\Core\View::make(BASE_PATH . '/app/Views/errors/500.php', [
        'title' => 'Server Error',
    ]);
});

set_error_handler(static function (
    int $severity,
    string $message,
    string $file,
    int $line
): bool {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

register_shutdown_function(static function (): void {
    $lastError = error_get_last();

    if (! is_array($lastError)) {
        return;
    }

    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (! in_array((int) ($lastError['type'] ?? 0), $fatalTypes, true)) {
        return;
    }

    app_write_log('app.shutdown_fatal', (string) ($lastError['message'] ?? 'Fatal shutdown error'), [
        'type' => (int) ($lastError['type'] ?? 0),
        'file' => (string) ($lastError['file'] ?? ''),
        'line' => (int) ($lastError['line'] ?? 0),
        'url' => $_SERVER['REQUEST_URI'] ?? null,
        'method' => $_SERVER['REQUEST_METHOD'] ?? null,
    ]);
});

session_name('travel_ops_session');
