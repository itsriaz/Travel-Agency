<?php

declare(strict_types=1);

namespace App\Services;

final class PasswordResetDeliveryService extends Service
{
    public function deliver(string $loginIdentifier, string $resetUrl): void
    {
        $mode = strtolower(trim((string) config('security.reset.delivery_mode', app_is_production() ? 'disabled' : 'log')));

        if ($mode === '' || $mode === 'disabled') {
            return;
        }

        if ($mode !== 'log') {
            if (app_debug_tools_enabled()) {
                throw new \RuntimeException('Unsupported password reset delivery mode configured: ' . $mode);
            }

            return;
        }

        $configuredPath = trim((string) config('security.reset.log_path', 'storage/logs/password_reset_links.log'));
        $logPath = $this->resolveLogPath($configuredPath);
        $directory = dirname($logPath);

        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $line = sprintf(
            "[%s] password reset for %s => %s%s",
            date('Y-m-d H:i:s'),
            $loginIdentifier,
            $resetUrl,
            PHP_EOL
        );

        file_put_contents($logPath, $line, FILE_APPEND | LOCK_EX);
    }

    private function resolveLogPath(string $configuredPath): string
    {
        $normalized = str_replace('\\', '/', trim($configuredPath));

        if ($normalized === '') {
            return base_path('/storage/logs/password_reset_links.log');
        }

        if (preg_match('/^[A-Za-z]:[\/\\\\]/', $configuredPath) === 1 || str_starts_with($normalized, '/')) {
            return $configuredPath;
        }

        return base_path('/' . ltrim($normalized, '/'));
    }
}
