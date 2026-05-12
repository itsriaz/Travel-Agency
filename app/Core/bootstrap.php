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

set_exception_handler(static function (Throwable $exception): void {
    http_response_code(500);

    if (config('app.debug', false)) {
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

session_name('travel_ops_session');
