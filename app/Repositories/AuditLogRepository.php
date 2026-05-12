<?php

declare(strict_types=1);

namespace App\Repositories;

final class AuditLogRepository extends BaseRepository
{
    public function recentFailureSummaryForUser(int $userId, int $days = 7): array
    {
        $cutoffAt = (new \DateTimeImmutable("-{$days} days"))->format('Y-m-d H:i:s');
        $statement = $this->db->prepare(
            'SELECT event_name, COUNT(*) AS total
             FROM audit_logs
             WHERE actor_user_id = :user_id
               AND event_name IN (
                   "auth.login.failed",
                   "auth.2fa.otp.failure",
                   "auth.reset.invalid_or_expired"
               )
               AND created_at >= :cutoff_at
             GROUP BY event_name
             ORDER BY total DESC'
        );
        $statement->execute([
            'user_id' => $userId,
            'cutoff_at' => $cutoffAt,
        ]);

        return $statement->fetchAll() ?: [];
    }

    public function relevantEventsForUser(int $userId, int $limit = 20): array
    {
        $statement = $this->db->prepare(
            'SELECT event_name, ip_address, created_at, payload_json
             FROM audit_logs
             WHERE actor_user_id = :user_id
                OR payload_json LIKE :target_pattern
             ORDER BY created_at DESC
             LIMIT :limit_rows'
        );
        $statement->bindValue(':user_id', $userId, \PDO::PARAM_INT);
        $statement->bindValue(':target_pattern', '%"target_user_id":' . $userId . '%');
        $statement->bindValue(':limit_rows', $limit, \PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll() ?: [];
    }
}
