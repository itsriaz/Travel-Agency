<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use PDO;
use Throwable;

final class HealthCheckService extends Service
{
    public function __construct(App $app)
    {
        parent::__construct($app);
    }

    public function run(): array
    {
        $checks = [
            'app_config' => $this->checkAppConfig(),
            'database' => $this->checkDatabase(),
            'migrations' => $this->checkMigrations(),
            'storage' => $this->checkStorage(),
        ];

        $healthy = true;
        foreach ($checks as $check) {
            if (($check['status'] ?? 'fail') !== 'ok') {
                $healthy = false;
                break;
            }
        }

        return [
            'status' => $healthy ? 'ok' : 'fail',
            'environment' => app_environment(),
            'timestamp' => date(DATE_ATOM),
            'checks' => $checks,
        ];
    }

    private function checkAppConfig(): array
    {
        $issues = [];

        if (app_is_production()) {
            if (! str_starts_with(strtolower((string) config('app.url', '')), 'https://')) {
                $issues[] = 'APP_URL must use HTTPS in production.';
            }

            if ((bool) config('app.debug', true)) {
                $issues[] = 'APP_DEBUG must be false in production.';
            }

            if (trim((string) config('security.health.token', '')) === '') {
                $issues[] = 'HEALTH_CHECK_TOKEN must be configured in production.';
            }

            if (! (bool) config('security.launcher_gate.enabled', false)) {
                $issues[] = 'LAUNCHER_GATE_ENABLED must be true in production.';
            }

            if (
                (bool) config('security.launcher_gate.allow_legacy_token', false)
                && strlen(trim((string) config('security.launcher_gate.token', ''))) < 32
            ) {
                $issues[] = 'LAUNCHER_GATE_TOKEN must be configured with a long random value.';
            }

            if (
                (bool) config('security.launcher_gate.signature_enabled', false)
                && trim((string) config('security.launcher_gate.public_key', '')) === ''
                && trim((string) config('security.launcher_gate.public_key_path', '')) === ''
            ) {
                $issues[] = 'Launcher signature mode requires a configured public key.';
            }

            if (trim((string) config('security.password.hash_driver', 'default')) === 'default') {
                $issues[] = 'PASSWORD_HASH_DRIVER should be explicitly set in production.';
            }

            if (
                (bool) config('security.launcher_gate.enabled', false)
                && ! (bool) config('security.launcher_gate.signature_enabled', false)
                && ! (bool) config('security.launcher_gate.allow_legacy_token', false)
            ) {
                $issues[] = 'Launcher gate must use signed requests or an explicitly enabled legacy token fallback.';
            }
        }

        return $issues === []
            ? ['status' => 'ok']
            : ['status' => 'fail', 'issues' => $issues];
    }

    private function checkDatabase(): array
    {
        try {
            /** @var PDO|null $db */
            $db = $this->app->get('db');
            if (! $db instanceof PDO) {
                return ['status' => 'fail', 'message' => 'Database service is unavailable.'];
            }

            $db->query('SELECT 1')->fetchColumn();

            return ['status' => 'ok'];
        } catch (Throwable) {
            return ['status' => 'fail', 'message' => 'Database check failed.'];
        }
    }

    private function checkMigrations(): array
    {
        try {
            /** @var PDO|null $db */
            $db = $this->app->get('db');
            if (! $db instanceof PDO) {
                return ['status' => 'fail', 'message' => 'Database service is unavailable.'];
            }

            $tableStatement = $db->prepare(
                'SELECT 1
                 FROM information_schema.tables
                 WHERE table_schema = DATABASE()
                   AND table_name = :table_name
                 LIMIT 1'
            );
            $tableStatement->execute(['table_name' => 'migrations']);
            if ($tableStatement->fetchColumn() === false) {
                return ['status' => 'fail', 'message' => 'Migrations table is missing.'];
            }

            $executed = $db->query('SELECT migration_name FROM migrations')->fetchAll(PDO::FETCH_COLUMN) ?: [];
            $migrationFiles = array_map(
                static fn (string $path): string => basename($path),
                glob(base_path('/database/migrations/*.php')) ?: []
            );
            $pending = array_values(array_diff($migrationFiles, $executed));

            return $pending === []
                ? ['status' => 'ok', 'applied' => count($executed)]
                : ['status' => 'fail', 'pending' => $pending];
        } catch (Throwable) {
            return ['status' => 'fail', 'message' => 'Migration check failed.'];
        }
    }

    private function checkStorage(): array
    {
        $paths = [
            'logs' => base_path('/storage/logs'),
            'documents' => base_path('/storage/documents'),
        ];

        $issues = [];
        foreach ($paths as $name => $path) {
            if (! is_dir($path)) {
                $issues[] = $name . ' directory is missing.';
                continue;
            }

            if (! is_writable($path)) {
                $issues[] = $name . ' directory is not writable.';
            }
        }

        return $issues === []
            ? ['status' => 'ok']
            : ['status' => 'fail', 'issues' => $issues];
    }
}
