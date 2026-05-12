<?php

declare(strict_types=1);

namespace App\Services;

final class PasswordResetDeliveryService extends Service
{
    public function deliver(string $loginIdentifier, string $resetUrl): void
    {
        $logPath = base_path('/storage/logs/password_reset_links.log');
        $line = sprintf(
            "[%s] password reset for %s => %s%s",
            date('Y-m-d H:i:s'),
            $loginIdentifier,
            $resetUrl,
            PHP_EOL
        );

        file_put_contents($logPath, $line, FILE_APPEND | LOCK_EX);
    }
}
