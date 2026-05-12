<?php

declare(strict_types=1);

namespace App\Repositories;

final class PasswordResetTokenRepository extends BaseRepository
{
    public function invalidateUnusedForUser(int $userId): void
    {
        $statement = $this->db->prepare(
            'UPDATE password_reset_tokens
             SET used_at = NOW()
             WHERE user_id = :user_id
               AND used_at IS NULL
               AND expires_at > NOW()'
        );
        $statement->execute(['user_id' => $userId]);
    }

    public function create(int $userId, string $selector, string $tokenHash, string $ipAddress, int $ttlMinutes): void
    {
        $expiresAt = (new \DateTimeImmutable("+{$ttlMinutes} minutes"))->format('Y-m-d H:i:s');
        $statement = $this->db->prepare(
            'INSERT INTO password_reset_tokens (
                user_id, selector, token_hash, requested_by_ip, expires_at, requested_at
             ) VALUES (
                :user_id, :selector, :token_hash, :requested_by_ip, :expires_at, NOW()
             )'
        );
        $statement->bindValue(':user_id', $userId, \PDO::PARAM_INT);
        $statement->bindValue(':selector', $selector);
        $statement->bindValue(':token_hash', $tokenHash);
        $statement->bindValue(':requested_by_ip', $ipAddress);
        $statement->bindValue(':expires_at', $expiresAt);
        $statement->execute();
    }

    public function findActiveBySelector(string $selector): ?array
    {
        $statement = $this->db->prepare(
            'SELECT *
             FROM password_reset_tokens
             WHERE selector = :selector
             LIMIT 1'
        );
        $statement->execute(['selector' => $selector]);
        $result = $statement->fetch();

        return $result !== false ? $result : null;
    }

    public function markUsed(int $id): void
    {
        $statement = $this->db->prepare(
            'UPDATE password_reset_tokens
             SET used_at = NOW()
             WHERE id = :id'
        );
        $statement->execute(['id' => $id]);
    }
}
