<?php

declare(strict_types=1);

namespace App\Helpers;

final class Session
{
    public static function start(): void
    {
        if (PHP_SAPI === 'cli') {
            if (! isset($_SESSION) || ! is_array($_SESSION)) {
                $_SESSION = [];
            }

            return;
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        self::prepareSavePath();

        session_start([
            'cookie_httponly' => true,
            'cookie_secure' => (bool) config('security.session.cookie_secure', self::isSecureRequest()),
            'cookie_samesite' => self::sameSiteValue((string) config('security.session.cookie_samesite', 'Lax')),
            'use_strict_mode' => true,
        ]);
    }

    public static function put(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public static function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public static function destroy(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            session_destroy();
        }
    }

    public static function touchAuthActivity(): void
    {
        self::put('_auth_last_activity', time());
    }

    public static function lastAuthActivity(): ?int
    {
        $value = self::get('_auth_last_activity');

        return is_int($value) ? $value : null;
    }

    private static function isSecureRequest(): bool
    {
        $https = (string) ($_SERVER['HTTPS'] ?? '');
        $forwardedProto = mb_strtolower(trim((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));

        return ($https !== '' && $https !== 'off') || $forwardedProto === 'https';
    }

    private static function prepareSavePath(): void
    {
        $configuredPath = trim((string) config('security.session.save_path', 'storage/runtime/sessions'));
        if ($configuredPath === '') {
            return;
        }

        $primaryPath = self::resolveSavePath($configuredPath);
        if (self::isUsableSessionPath($primaryPath)) {
            session_save_path($primaryPath);

            return;
        }

        if (! self::isProductionEnvironment()) {
            $fallbackPath = rtrim(sys_get_temp_dir(), "\\/") . DIRECTORY_SEPARATOR . 'travel-agency-sessions';
            if (self::isUsableSessionPath($fallbackPath)) {
                session_save_path($fallbackPath);

                return;
            }
        }

        throw new \RuntimeException('Secure session storage is not writable.');
    }

    private static function sameSiteValue(string $value): string
    {
        $normalized = ucfirst(mb_strtolower(trim($value)));

        return in_array($normalized, ['Lax', 'Strict', 'None'], true) ? $normalized : 'Lax';
    }

    private static function resolveSavePath(string $configuredPath): string
    {
        return preg_match('/^[A-Za-z]:[\\\\\\/]/', $configuredPath) === 1 || str_starts_with($configuredPath, '/')
            ? $configuredPath
            : base_path('/' . ltrim($configuredPath, '/'));
    }

    private static function isUsableSessionPath(string $path): bool
    {
        if (! is_dir($path) && ! @mkdir($path, 0775, true) && ! is_dir($path)) {
            return false;
        }

        if (! is_writable($path)) {
            return false;
        }

        $probeFile = rtrim($path, "\\/") . DIRECTORY_SEPARATOR . '.__session_probe_' . bin2hex(random_bytes(6));
        $wroteProbe = @file_put_contents($probeFile, 'probe', LOCK_EX);
        if ($wroteProbe === false) {
            return false;
        }

        clearstatcache(true, $probeFile);
        $removedProbe = @unlink($probeFile);
        if (! $removedProbe && is_file($probeFile)) {
            return false;
        }

        return true;
    }

    private static function isProductionEnvironment(): bool
    {
        return mb_strtolower(trim((string) config('app.env', 'local'))) === 'production';
    }
}
