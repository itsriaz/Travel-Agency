<?php

declare(strict_types=1);

namespace App\Repositories;

final class SecurityThrottleRepository extends BaseRepository
{
    public function countRecentFailures(string $actionName, string $subjectKey, string $ipAddress, int $windowMinutes): int
    {
        $cutoffAt = (new \DateTimeImmutable("-{$windowMinutes} minutes"))->format('Y-m-d H:i:s');
        $statement = $this->db->prepare(
            'SELECT COUNT(*) AS total
             FROM security_throttle_events
             WHERE action_name = :action_name
               AND subject_key = :subject_key
               AND ip_address = :ip_address
               AND was_successful = 0
               AND attempted_at >= :cutoff_at'
        );
        $statement->execute([
            'action_name' => $actionName,
            'subject_key' => $subjectKey,
            'ip_address' => $ipAddress,
            'cutoff_at' => $cutoffAt,
        ]);

        return (int) $statement->fetchColumn();
    }

    public function countRecent(string $actionName, string $subjectKey, string $ipAddress, int $windowMinutes): int
    {
        $cutoffAt = (new \DateTimeImmutable("-{$windowMinutes} minutes"))->format('Y-m-d H:i:s');
        $statement = $this->db->prepare(
            'SELECT COUNT(*) AS total
             FROM security_throttle_events
             WHERE action_name = :action_name
               AND subject_key = :subject_key
               AND ip_address = :ip_address
               AND attempted_at >= :cutoff_at'
        );
        $statement->bindValue(':action_name', $actionName);
        $statement->bindValue(':subject_key', $subjectKey);
        $statement->bindValue(':ip_address', $ipAddress);
        $statement->bindValue(':cutoff_at', $cutoffAt);
        $statement->execute();

        return (int) $statement->fetchColumn();
    }

    public function record(string $actionName, string $subjectKey, string $ipAddress, bool $wasSuccessful): void
    {
        $statement = $this->db->prepare(
            'INSERT INTO security_throttle_events (action_name, subject_key, ip_address, was_successful, attempted_at)
             VALUES (:action_name, :subject_key, :ip_address, :was_successful, NOW())'
        );
        $statement->execute([
            'action_name' => $actionName,
            'subject_key' => $subjectKey,
            'ip_address' => $ipAddress,
            'was_successful' => $wasSuccessful ? 1 : 0,
        ]);
    }

    public function clearFailures(string $actionName, string $subjectKey, string $ipAddress): void
    {
        $statement = $this->db->prepare(
            'DELETE FROM security_throttle_events
             WHERE action_name = :action_name
               AND subject_key = :subject_key
               AND ip_address = :ip_address
               AND was_successful = 0'
        );
        $statement->execute([
            'action_name' => $actionName,
            'subject_key' => $subjectKey,
            'ip_address' => $ipAddress,
        ]);
    }
}
