<?php

declare(strict_types=1);

namespace App\Repositories;

final class LoginAttemptRepository extends BaseRepository
{
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
             VALUES (:login_key, :ip_address, :user_id, :was_successful, NOW())'
        );
        $statement->execute([
            'login_key' => $loginKey,
            'ip_address' => $ipAddress,
            'user_id' => $userId,
            'was_successful' => $wasSuccessful ? 1 : 0,
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
