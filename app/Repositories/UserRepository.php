<?php

declare(strict_types=1);

namespace App\Repositories;

final class UserRepository extends BaseRepository
{
    public function findForLogin(string $login): ?array
    {
        $statement = $this->db->prepare(
            'SELECT u.id, u.name, u.username, u.email, u.password_hash, u.default_branch_id,
                    u.must_change_password, u.session_version, u.two_factor_enabled, r.code AS role_code
             FROM users u
             INNER JOIN roles r ON r.id = u.role_id
             WHERE (LOWER(u.email) = :login_email OR LOWER(u.username) = :login_username) AND u.is_active = 1
             LIMIT 1'
        );
        $normalizedLogin = mb_strtolower($login);
        $statement->execute([
            'login_email' => $normalizedLogin,
            'login_username' => $normalizedLogin,
        ]);

        $result = $statement->fetch();

        return $result !== false ? $result : null;
    }

    public function touchLastLogin(int $userId): void
    {
        $statement = $this->db->prepare('UPDATE users SET last_login_at = NOW() WHERE id = :id');
        $statement->execute(['id' => $userId]);
    }

    public function rehashPassword(int $userId, string $password): void
    {
        $statement = $this->db->prepare('UPDATE users SET password_hash = :password_hash WHERE id = :id');
        $statement->execute([
            'id' => $userId,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        ]);
    }

    public function branchIdsForUser(int $userId, string $roleCode): array
    {
        if ($roleCode === 'super_admin') {
            $statement = $this->db->query('SELECT id FROM branches WHERE is_active = 1 ORDER BY id');
            return $statement->fetchAll(\PDO::FETCH_COLUMN) ?: [];
        }

        $statement = $this->db->prepare(
            'SELECT branch_id
             FROM user_branch_access
             WHERE user_id = :user_id
             ORDER BY branch_id'
        );
        $statement->execute(['user_id' => $userId]);

        return $statement->fetchAll(\PDO::FETCH_COLUMN) ?: [];
    }

    public function findById(int $userId): ?array
    {
        $statement = $this->db->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $userId]);
        $result = $statement->fetch();

        return $result !== false ? $result : null;
    }

    public function findForSession(int $userId): ?array
    {
        $statement = $this->db->prepare(
            'SELECT u.id, u.name, u.username, u.email, u.default_branch_id,
                    u.must_change_password, u.session_version, u.two_factor_enabled, r.code AS role_code
             FROM users u
             INNER JOIN roles r ON r.id = u.role_id
             WHERE u.id = :id AND u.is_active = 1
             LIMIT 1'
        );
        $statement->execute(['id' => $userId]);
        $result = $statement->fetch();

        return $result !== false ? $result : null;
    }

    public function currentAuthState(int $userId): ?array
    {
        $statement = $this->db->prepare(
            'SELECT id, is_active, must_change_password, session_version
                    , two_factor_enabled
             FROM users
             WHERE id = :id
             LIMIT 1'
        );
        $statement->execute(['id' => $userId]);
        $result = $statement->fetch();

        return $result !== false ? $result : null;
    }

    public function updatePassword(int $userId, string $password, bool $mustChangePassword, ?string $reason): void
    {
        $statement = $this->db->prepare(
            'UPDATE users
             SET password_hash = :password_hash,
                 must_change_password = :must_change_password_value,
                 force_password_change_reason = :reason,
                 password_changed_at = NOW(),
                 password_reset_required_at = CASE WHEN :must_change_password_case = 1 THEN NOW() ELSE NULL END,
                 session_version = session_version + 1
             WHERE id = :id'
        );
        $statement->execute([
            'id' => $userId,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'must_change_password_value' => $mustChangePassword ? 1 : 0,
            'must_change_password_case' => $mustChangePassword ? 1 : 0,
            'reason' => $reason,
        ]);
    }

    public function storePendingTwoFactorSecret(int $userId, string $encryptedSecret): void
    {
        $statement = $this->db->prepare(
            'UPDATE users
             SET two_factor_secret_pending_encrypted = :secret,
                 two_factor_setup_started_at = NOW()
             WHERE id = :id'
        );
        $statement->execute([
            'id' => $userId,
            'secret' => $encryptedSecret,
        ]);
    }

    public function activateTwoFactorSecret(int $userId, string $encryptedSecret): void
    {
        $statement = $this->db->prepare(
            'UPDATE users
             SET two_factor_secret_encrypted = :secret,
                 two_factor_secret_pending_encrypted = NULL,
                 two_factor_enabled = 1,
                 two_factor_confirmed_at = NOW(),
                 session_version = session_version + 1
             WHERE id = :id'
        );
        $statement->execute([
            'id' => $userId,
            'secret' => $encryptedSecret,
        ]);
    }

    public function resetTwoFactor(int $userId): void
    {
        $statement = $this->db->prepare(
            'UPDATE users
             SET two_factor_enabled = 0,
                 two_factor_confirmed_at = NULL,
                 two_factor_secret_encrypted = NULL,
                 two_factor_secret_pending_encrypted = NULL,
                 two_factor_setup_started_at = NULL,
                 session_version = session_version + 1
             WHERE id = :id'
        );
        $statement->execute(['id' => $userId]);
    }

    public function incrementSessionVersion(int $userId): void
    {
        $statement = $this->db->prepare(
            'UPDATE users
             SET session_version = session_version + 1
             WHERE id = :id'
        );
        $statement->execute(['id' => $userId]);
    }

    public function listActiveUsers(): array
    {
        $statement = $this->db->query(
            'SELECT id, name, username, email
             FROM users
             WHERE is_active = 1
             ORDER BY name'
        );

        return $statement->fetchAll() ?: [];
    }
}
