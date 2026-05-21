<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\App;

abstract class Service
{
    public function __construct(protected readonly App $app)
    {
    }

    protected function assertFinancialAdminActor(int $actorUserId, string $message): void
    {
        /** @var \PDO|null $db */
        $db = $this->app->get('db');
        if (! $db instanceof \PDO) {
            throw new \RuntimeException('Authorization could not be verified.');
        }

        $statement = $db->prepare(
            'SELECT r.code
             FROM users u
             INNER JOIN roles r ON r.id = u.role_id
             WHERE u.id = :user_id
               AND u.is_active = 1
             LIMIT 1'
        );
        $statement->execute(['user_id' => $actorUserId]);
        $roleCode = (string) ($statement->fetchColumn() ?: '');

        if (! in_array($roleCode, ['super_admin', 'branch_admin'], true)) {
            throw new \RuntimeException($message);
        }
    }
}
