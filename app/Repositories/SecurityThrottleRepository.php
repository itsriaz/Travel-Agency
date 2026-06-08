<?php

declare(strict_types=1);

namespace App\Repositories;

final class SecurityThrottleRepository extends BaseRepository
{
    private function currentAttemptedAt(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d H:i:s');
    }

    public function currentLockState(string $actionName, string $subjectKey, string $ipAddress, int $windowMinutes, int $maxAttempts): ?array
    {
        $limit = max(1, $maxAttempts);
        $statement = $this->db->query(
            'SELECT attempted_at
             FROM security_throttle_events
             WHERE action_name = ' . $this->db->quote($actionName) . '
               AND subject_key = ' . $this->db->quote($subjectKey) . '
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
             VALUES (:action_name, :subject_key, :ip_address, :was_successful, :attempted_at)'
        );
        $statement->execute([
            'action_name' => $actionName,
            'subject_key' => $subjectKey,
            'ip_address' => $ipAddress,
            'was_successful' => $wasSuccessful ? 1 : 0,
            'attempted_at' => $this->currentAttemptedAt(),
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
