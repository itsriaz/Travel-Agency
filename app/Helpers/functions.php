<?php

declare(strict_types=1);

function app(): \App\Core\App
{
    global $app;

    return $app;
}

function config(string $key, mixed $default = null): mixed
{
    return app()->config($key, $default);
}

function base_path(string $path = ''): string
{
    return app()->basePath($path);
}

function request_base_path(): string
{
    $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? ''));

    if ($scriptName === '') {
        return '';
    }

    $directory = str_replace('\\', '/', dirname($scriptName));

    if ($directory === '/' || $directory === '\\' || $directory === '.') {
        return '';
    }

    return rtrim($directory, '/');
}

function request_base_url(): ?string
{
    $host = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));

    if ($host === '') {
        return null;
    }

    $https = (string) ($_SERVER['HTTPS'] ?? '');
    $forwardedProto = (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '');
    $scheme = ($https !== '' && $https !== 'off') || strtolower($forwardedProto) === 'https'
        ? 'https'
        : 'http';

    return $scheme . '://' . $host . request_base_path();
}

function url(string $path = '/'): string
{
    $baseUrl = rtrim((string) (request_base_url() ?? config('app.url', '')), '/');
    $path = '/' . ltrim($path, '/');

    return $baseUrl . ($path === '/' ? '' : $path);
}

function asset(string $path): string
{
    $scriptFilename = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_FILENAME'] ?? ''));
    $isPublicFrontController = str_ends_with($scriptFilename, '/public/index.php');

    return url(($isPublicFrontController ? '/' : '/public/') . ltrim($path, '/'));
}

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function app_environment(): string
{
    return mb_strtolower(trim((string) config('app.env', 'local')));
}

function app_is_production(): bool
{
    return app_environment() === 'production';
}

function app_debug_tools_enabled(): bool
{
    return ! app_is_production() && (bool) config('app.debug', false);
}

function app_request_is_secure(): bool
{
    $https = (string) ($_SERVER['HTTPS'] ?? '');
    $forwardedProto = mb_strtolower(trim((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));

    return ($https !== '' && $https !== 'off') || $forwardedProto === 'https';
}

function app_send_security_headers(): void
{
    if (PHP_SAPI === 'cli' || headers_sent() || ! (bool) config('security.headers.enabled', true)) {
        return;
    }

    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: ' . (string) config('security.headers.referrer_policy', 'strict-origin-when-cross-origin'));
    header('Permissions-Policy: ' . (string) config('security.headers.permissions_policy', 'camera=(), microphone=(), geolocation=(), payment=()'));
    header('Content-Security-Policy: ' . (string) config('security.headers.content_security_policy', "default-src 'self'; frame-ancestors 'none'; object-src 'none'"));

    if ((bool) config('security.headers.hsts_enabled', false) && app_request_is_secure()) {
        header('Strict-Transport-Security: max-age=' . (int) config('security.headers.hsts_max_age', 31536000) . '; includeSubDomains');
    }
}

function app_log_path(string $filename = 'app-runtime.log'): string
{
    $sanitized = trim(str_replace(['\\', '..'], ['/', ''], $filename));

    if ($sanitized === '') {
        $sanitized = 'app-runtime.log';
    }

    return base_path('/storage/logs/' . ltrim($sanitized, '/'));
}

function app_request_id(): string
{
    static $requestId = null;

    if ($requestId !== null) {
        return $requestId;
    }

    $incoming = trim((string) ($_SERVER['HTTP_X_REQUEST_ID'] ?? $_SERVER['HTTP_X_CORRELATION_ID'] ?? ''));
    if ($incoming !== '' && preg_match('/^[A-Za-z0-9._:-]{8,80}$/', $incoming) === 1) {
        $requestId = $incoming;
        return $requestId;
    }

    try {
        $requestId = bin2hex(random_bytes(8));
    } catch (\Throwable) {
        $requestId = str_replace('.', '', uniqid('req', true));
    }

    return $requestId;
}

function app_request_log_context(): array
{
    $requestValue = static function (string $key): mixed {
        if (array_key_exists($key, $_POST)) {
            return $_POST[$key];
        }

        if (array_key_exists($key, $_GET)) {
            return $_GET[$key];
        }

        return null;
    };

    $bookingId = (int) ($requestValue('booking_id') ?? 0);
    $serviceId = (int) ($requestValue('service_id') ?? 0);
    $customerReceiptId = (int) ($requestValue('customer_receipt_id') ?? 0);
    $supplierPaymentId = (int) ($requestValue('supplier_payment_id') ?? 0);
    $bookingReference = trim((string) ($requestValue('booking_reference') ?? ''));
    $quickSearch = trim((string) ($requestValue('q') ?? ''));

    return [
        'request_id' => app_request_id(),
        'url' => $_SERVER['REQUEST_URI'] ?? null,
        'method' => $_SERVER['REQUEST_METHOD'] ?? null,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
        'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? substr((string) $_SERVER['HTTP_USER_AGENT'], 0, 220) : null,
        'referer' => isset($_SERVER['HTTP_REFERER']) ? substr((string) $_SERVER['HTTP_REFERER'], 0, 220) : null,
        'booking_id' => $bookingId > 0 ? $bookingId : null,
        'booking_reference' => $bookingReference !== '' ? substr($bookingReference, 0, 80) : null,
        'quick_search' => $quickSearch !== '' ? substr($quickSearch, 0, 120) : null,
        'service_id' => $serviceId > 0 ? $serviceId : null,
        'customer_receipt_id' => $customerReceiptId > 0 ? $customerReceiptId : null,
        'supplier_payment_id' => $supplierPaymentId > 0 ? $supplierPaymentId : null,
    ];
}

function app_write_log(string $channel, string $message, array $context = []): void
{
    $path = app_log_path();
    $directory = dirname($path);

    if (! is_dir($directory)) {
        @mkdir($directory, 0775, true);
    }

    $normalizedContext = [];
    foreach ($context as $key => $value) {
        if (is_scalar($value) || $value === null) {
            $normalizedContext[$key] = $value;
            continue;
        }

        try {
            $normalizedContext[$key] = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (\Throwable) {
            $normalizedContext[$key] = '[unserializable]';
        }
    }

    $line = sprintf(
        "[%s] %s: %s%s",
        date('Y-m-d H:i:s'),
        $channel,
        $message,
        $normalizedContext !== [] ? ' | ' . json_encode($normalizedContext, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : ''
    );

    @file_put_contents($path, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function app_log_exception(\Throwable $exception, string $channel = 'app.exception'): void
{
    app_write_log($channel, $exception->getMessage(), array_merge(app_request_log_context(), [
        'type' => $exception::class,
        'file' => $exception->getFile(),
        'line' => $exception->getLine(),
        'trace' => app_debug_tools_enabled() ? $exception->getTraceAsString() : null,
    ]));
}

function app_path(string $uri): string
{
    $base = request_base_path();

    if ($base === '') {
        $base = parse_url(config('app.url', ''), PHP_URL_PATH) ?: '';
    }

    $base = rtrim($base, '/');

    if ($base !== '' && str_starts_with($uri, $base)) {
        $uri = substr($uri, strlen($base)) ?: '/';
    }

    return '/' . ltrim($uri, '/');
}
