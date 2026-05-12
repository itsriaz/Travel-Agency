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

    private function registerCoreServices(): void
    {
        Session::start();

        $this->set('view', new View($this));
        $this->set('db', Database::connection($this->config('database')));
        $this->set('gate', new Gate());
    }
}
