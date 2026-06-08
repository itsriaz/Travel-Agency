<?php

declare(strict_types=1);

namespace App\Core;

use App\Helpers\Session;

final class App
{
    private array $config = [];

    private array $services = [];

    public function __construct(private readonly string $basePath)
    {
    }

    public static function bootstrap(string $basePath): self
    {
        global $app;

        $app = new self($basePath);
        $app->loadConfig();
        $app->validateProductionConfiguration();
        date_default_timezone_set($app->config('app.timezone', 'UTC'));
        $app->registerCoreServices();

        return $app;
    }

    public function basePath(string $path = ''): string
    {
        return $this->basePath . ($path !== '' ? '/' . ltrim($path, '/') : '');
    }

    public function config(string $key, mixed $default = null): mixed
    {
        $segments = explode('.', $key);
        $value = $this->config;

        foreach ($segments as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return $default;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    public function set(string $key, mixed $service): void
    {
        $this->services[$key] = $service;
    }

    public function get(string $key): mixed
    {
        return $this->services[$key] ?? null;
    }

    private function loadConfig(): void
    {
        foreach (glob($this->basePath('/config/*.php')) ?: [] as $file) {
            $name = pathinfo($file, PATHINFO_FILENAME);
            $this->config[$name] = require $file;
        }
    }

    private function validateProductionConfiguration(): void
    {
        $appEnv = mb_strtolower(trim((string) $this->config('app.env', 'local')));
        if ($appEnv !== 'production') {
            return;
        }

        $issues = [];
        $defaultAppKey = 'base64:Wm5uWGQ0blFSbVQ4ME5hL2p3VVRYeG9NcnN0Qk9ud3pPaGRYRE1vV0d6TT0=';
        $appKey = trim((string) $this->config('app.key', ''));
        $appUrl = mb_strtolower(trim((string) $this->config('app.url', '')));
        $dbHost = trim((string) $this->config('database.host', ''));
        $dbDatabase = trim((string) $this->config('database.database', ''));
        $dbUsername = trim((string) $this->config('database.username', ''));
        $dbPassword = (string) $this->config('database.password', '');
        $launcherGateEnabled = (bool) $this->config('security.launcher_gate.enabled', false);
        $launcherGateToken = trim((string) $this->config('security.launcher_gate.token', ''));
        $launcherSignatureEnabled = (bool) $this->config('security.launcher_gate.signature_enabled', false);
        $launcherAllowLegacyToken = (bool) $this->config('security.launcher_gate.allow_legacy_token', false);
        $launcherPublicKey = trim((string) $this->config('security.launcher_gate.public_key', ''));
        $launcherPublicKeyPath = trim((string) $this->config('security.launcher_gate.public_key_path', ''));
        $passwordHashDriver = strtolower(trim((string) $this->config('security.password.hash_driver', 'default')));

        if ($appKey === '' || $appKey === $defaultAppKey) {
            $issues[] = 'APP_KEY must be explicitly set for production.';
        }

        if (! $this->isValidAppKey($appKey)) {
            $issues[] = 'APP_KEY must be a base64-encoded 32-byte key.';
        }

        if ($appUrl === '' || str_contains($appUrl, 'localhost') || str_contains($appUrl, '127.0.0.1')) {
            $issues[] = 'APP_URL must be set to the real production URL.';
        }

        if (! str_starts_with($appUrl, 'https://')) {
            $issues[] = 'APP_URL must use HTTPS in production.';
        }

        if ((bool) $this->config('security.session.cookie_secure', false) !== true) {
            $issues[] = 'SESSION_COOKIE_SECURE must be true in production.';
        }

        if ((bool) $this->config('security.trusted_device.cookie_secure', false) !== true) {
            $issues[] = 'TRUSTED_DEVICE_COOKIE_SECURE must be true in production.';
        }

        if (! $launcherGateEnabled) {
            $issues[] = 'LAUNCHER_GATE_ENABLED must be true in production.';
        }

        if ($launcherGateEnabled && $launcherAllowLegacyToken && strlen($launcherGateToken) < 32) {
            $issues[] = 'LAUNCHER_GATE_TOKEN must be a long random token in production.';
        }

        if ($launcherSignatureEnabled && $launcherPublicKey === '' && $launcherPublicKeyPath === '') {
            $issues[] = 'Launcher signature mode requires LAUNCHER_GATE_PUBLIC_KEY or LAUNCHER_GATE_PUBLIC_KEY_PATH.';
        }

        if ($launcherGateEnabled && ! $launcherSignatureEnabled && ! $launcherAllowLegacyToken) {
            $issues[] = 'Launcher gate must use either signed requests or an explicitly enabled legacy token fallback.';
        }

        if ($passwordHashDriver === 'default') {
            $issues[] = 'PASSWORD_HASH_DRIVER should be explicitly set to argon2id or bcrypt in production.';
        }

        if (
            $dbHost === '127.0.0.1'
            && $dbDatabase === 'travel_agency_ops'
            && $dbUsername === 'root'
            && $dbPassword === ''
        ) {
            $issues[] = 'Production database credentials are still using the local fallback defaults.';
        }

        if ($issues !== []) {
            throw new \RuntimeException('Production configuration is incomplete: ' . implode(' ', $issues));
        }
    }

    private function isValidAppKey(string $appKey): bool
    {
        if (! str_starts_with($appKey, 'base64:')) {
            return false;
        }

        $decoded = base64_decode(substr($appKey, 7), true);

        return is_string($decoded) && strlen($decoded) === 32;
    }

    private function registerCoreServices(): void
    {
        Session::start();

        $this->set('view', new View($this));
        $this->set('db', Database::connection($this->config('database')));
        $this->set('gate', new Gate());
    }
}
