<?php

declare(strict_types=1);

namespace App\Helpers;

use App\Core\App;
use PDO;
use Throwable;

final class AuditLog
{
    public static function record(App $app, string $event, array $payload = []): void
    {
        /** @var PDO|null $db */
        $db = $app->get('db');

        if (! $db instanceof PDO) {
            return;
        }

        try {
            $statement = $db->prepare(
                'INSERT INTO audit_logs (event_name, actor_user_id, ip_address, user_agent, payload_json, created_at)
                 VALUES (:event_name, :actor_user_id, :ip_address, :user_agent, :payload_json, NOW())'
            );

            $statement->execute([
                'event_name' => $event,
                'actor_user_id' => $payload['user_id'] ?? null,
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                'user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
                'payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        } catch (Throwable) {
            // Foundation stub: audit logging must never break the request lifecycle.
        }
    }
}
