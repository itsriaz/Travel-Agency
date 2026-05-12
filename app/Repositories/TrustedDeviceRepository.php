<?php

declare(strict_types=1);

namespace App\Repositories;

final class TrustedDeviceRepository extends BaseRepository
{
    public function create(
        int $userId,
        string $selector,
        string $tokenHash,
        string $deviceLabel,
        string $userAgentHash,
        ?string $ipAddress,
        int $validDays
    ): void {
        $expiresAt = (new \DateTimeImmutable("+{$validDays} days"))->format('Y-m-d H:i:s');
        $statement = $this->db->prepare(
            'INSERT INTO trusted_devices (
                user_id, selector, token_hash, device_label, user_agent_hash, last_ip_address,
                created_at, last_used_at, expires_at, revoked_at
             ) VALUES (
                :user_id, :selector, :token_hash, :device_label, :user_agent_hash, :last_ip_address,
                NOW(), NOW(), :expires_at, NULL
             )'
        );
        $statement->execute([
            'user_id' => $userId,
            'selector' => $selector,
            'token_hash' => $tokenHash,
            'device_label' => $deviceLabel,
            'user_agent_hash' => $userAgentHash,
            'last_ip_address' => $ipAddress,
            'expires_at' => $expiresAt,
        ]);
    }

    public function findActiveBySelector(int $userId, string $selector): ?array
    {
        $statement = $this->db->prepare(
            'SELECT *
             FROM trusted_devices
             WHERE user_id = :user_id
               AND selector = :selector
               AND revoked_at IS NULL
               AND expires_at > NOW()
             LIMIT 1'
        );
        $statement->execute([
            'user_id' => $userId,
            'selector' => $selector,
        ]);

        $result = $statement->fetch();
        return $result !== false ? $result : null;
    }

    public function touchUsage(int $deviceId, ?string $ipAddress): void
    {
        $statement = $this->db->prepare(
            'UPDATE trusted_devices
             SET last_used_at = NOW(),
                 last_ip_address = :ip_address
             WHERE id = :id'
        );
        $statement->execute([
            'id' => $deviceId,
            'ip_address' => $ipAddress,
        ]);
    }

    public function revokeAllForUser(int $userId): void
    {
        $statement = $this->db->prepare(
            'UPDATE trusted_devices
             SET revoked_at = NOW()
             WHERE user_id = :user_id
               AND revoked_at IS NULL'
        );
        $statement->execute(['user_id' => $userId]);
    }

    public function revokeById(int $userId, int $deviceId): void
    {
        $statement = $this->db->prepare(
            'UPDATE trusted_devices
             SET revoked_at = NOW()
             WHERE user_id = :user_id
               AND id = :id
               AND revoked_at IS NULL'
        );
        $statement->execute([
            'user_id' => $userId,
            'id' => $deviceId,
        ]);
    }

    public function listForUser(int $userId): array
    {
        $statement = $this->db->prepare(
            'SELECT id, device_label, last_ip_address, created_at, last_used_at, expires_at, revoked_at
             FROM trusted_devices
             WHERE user_id = :user_id
             ORDER BY created_at DESC'
        );
        $statement->execute(['user_id' => $userId]);

        return $statement->fetchAll() ?: [];
    }
}
