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
    return url('/public/' . ltrim($path, '/'));
}

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
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
