<?php

declare(strict_types=1);

namespace App\Repositories;

final class TwoFactorRecoveryCodeRepository extends BaseRepository
{
    public function replaceCodes(int $userId, array $plainCodes): void
    {
        $delete = $this->db->prepare('DELETE FROM user_two_factor_recovery_codes WHERE user_id = :user_id');
        $delete->execute(['user_id' => $userId]);

        $insert = $this->db->prepare(
            'INSERT INTO user_two_factor_recovery_codes (user_id, code_hash, created_at)
             VALUES (:user_id, :code_hash, NOW())'
        );

        foreach ($plainCodes as $code) {
            $insert->execute([
                'user_id' => $userId,
                'code_hash' => password_hash($code, PASSWORD_DEFAULT),
            ]);
        }
    }

    public function consumeMatchingCode(int $userId, string $code): bool
    {
        $trimmed = strtoupper(trim($code));
        $normalized = str_replace('-', '', $trimmed);
        $candidates = [$trimmed];

        if (strlen($normalized) === 8) {
            $candidates[] = substr($normalized, 0, 4) . '-' . substr($normalized, 4, 4);
            $candidates[] = $normalized;
        }

        $statement = $this->db->prepare(
            'SELECT id, code_hash
             FROM user_two_factor_recovery_codes
             WHERE user_id = :user_id
               AND used_at IS NULL'
        );
        $statement->execute(['user_id' => $userId]);

        foreach ($statement->fetchAll() ?: [] as $row) {
            foreach (array_unique($candidates) as $candidate) {
                if (! password_verify($candidate, $row['code_hash'])) {
                    continue;
                }

                $update = $this->db->prepare(
                    'UPDATE user_two_factor_recovery_codes
                     SET used_at = NOW()
                     WHERE id = :id'
                );
                $update->execute(['id' => $row['id']]);

                return true;
            }
        }

        return false;
    }
}
