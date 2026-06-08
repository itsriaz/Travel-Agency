<?php

declare(strict_types=1);

namespace App\Repositories;

final class LoginAttemptRepository extends BaseRepository
{
    private function currentAttemptedAt(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d H:i:s');
    }

    public function currentLockState(string $loginKey, string $ipAddress, int $windowMinutes, int $maxAttempts): ?array
    {
        $limit = max(1, $maxAttempts);
        $statement = $this->db->query(
            'SELECT attempted_at
             FROM auth_login_attempts
             WHERE login_key = ' . $this->db->quote($loginKey) . '
               AND ip_address = ' . $this->db->quote($ipAddress) . '
               AND was_successful = 0
             ORDER BY attempted_at DESC
             LIMIT ' . $limit
        );

        $rows = $statement->fetchAll(\PDO::FETCH_ASSOC);
        if (count($rows) < $maxAttempts) {
            return null;
        }

        $oldestAttempt = end($rows);
        $attemptedAt = isset($oldestAttempt['attempted_at']) ? trim((string) $oldestAttempt['attempted_at']) : '';
        if ($attemptedAt === '') {
            return null;
        }

        $retryAt = (new \DateTimeImmutable($attemptedAt))->modify("+{$windowMinutes} minutes");
        $remainingSeconds = $retryAt->getTimestamp() - (new \DateTimeImmutable())->getTimestamp();

        if ($remainingSeconds <= 0) {
            return null;
        }

        return [
            'remaining_seconds' => $remainingSeconds,
            'retry_at' => $retryAt->format('Y-m-d H:i:s'),
        ];
    }

    public function countRecentFailures(string $loginKey, string $ipAddress, int $windowMinutes): int
    {
        $cutoffAt = (new \DateTimeImmutable("-{$windowMinutes} minutes"))->format('Y-m-d H:i:s');
        $statement = $this->db->prepare(
            'SELECT COUNT(*) AS total
             FROM auth_login_attempts
             WHERE login_key = :login_key
               AND ip_address = :ip_address
               AND was_successful = 0
               AND attempted_at >= :cutoff_at'
        );
        $statement->bindValue(':login_key', $loginKey);
        $statement->bindValue(':ip_address', $ipAddress);
        $statement->bindValue(':cutoff_at', $cutoffAt);
        $statement->execute();

        return (int) $statement->fetchColumn();
    }

    public function record(string $loginKey, string $ipAddress, bool $wasSuccessful, ?int $userId = null): void
    {
        $statement = $this->db->prepare(
            'INSERT INTO auth_login_attempts (login_key, ip_address, user_id, was_successful, attempted_at)
             VALUES (:login_key, :ip_address, :user_id, :was_successful, :attempted_at)'
        );
        $statement->execute([
            'login_key' => $loginKey,
            'ip_address' => $ipAddress,
            'user_id' => $userId,
            'was_successful' => $wasSuccessful ? 1 : 0,
            'attempted_at' => $this->currentAttemptedAt(),
        ]);
    }

    public function clearFailures(string $loginKey, string $ipAddress): void
    {
        $statement = $this->db->prepare(
            'DELETE FROM auth_login_attempts
             WHERE login_key = :login_key
               AND ip_address = :ip_address
               AND was_successful = 0'
        );
        $statement->execute([
            'login_key' => $loginKey,
            'ip_address' => $ipAddress,
        ]);
    }
}
