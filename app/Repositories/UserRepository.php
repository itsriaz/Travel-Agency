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
        $statement = $this->db->prepare(
            'SELECT u.*, r.code AS role_code, r.name AS role_name, b.name AS default_branch_name
             FROM users u
             INNER JOIN roles r ON r.id = u.role_id
             INNER JOIN branches b ON b.id = u.default_branch_id
             WHERE u.id = :id
             LIMIT 1'
        );
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
            'SELECT u.id, u.name, u.username, u.email, r.code AS role_code, b.name AS default_branch_name
             FROM users u
             INNER JOIN roles r ON r.id = u.role_id
             INNER JOIN branches b ON b.id = u.default_branch_id
             WHERE u.is_active = 1
             ORDER BY u.name'
        );

        return $statement->fetchAll() ?: [];
    }

    public function listAssignableRoles(): array
    {
        $statement = $this->db->query(
            "SELECT id, code, name
             FROM roles
             WHERE code IN ('super_admin', 'branch_admin', 'employee')
             ORDER BY FIELD(code, 'employee', 'branch_admin', 'super_admin')"
        );

        return $statement->fetchAll() ?: [];
    }

    public function listActiveBranches(): array
    {
        $statement = $this->db->query(
            'SELECT id, code, name
             FROM branches
             WHERE is_active = 1
             ORDER BY name'
        );

        return $statement->fetchAll() ?: [];
    }

    public function branchAccessIds(int $userId): array
    {
        $statement = $this->db->prepare(
            'SELECT branch_id
             FROM user_branch_access
             WHERE user_id = :user_id
             ORDER BY branch_id'
        );
        $statement->execute(['user_id' => $userId]);

        return array_map('intval', $statement->fetchAll(\PDO::FETCH_COLUMN) ?: []);
    }

    public function updateRoleAndBranchAccess(int $userId, string $roleCode, int $defaultBranchId, array $branchIds): void
    {
        $this->transaction(function () use ($userId, $roleCode, $defaultBranchId, $branchIds): void {
            $roleStatement = $this->db->prepare(
                "SELECT id
                 FROM roles
                 WHERE code = :code
                   AND code IN ('super_admin', 'branch_admin', 'employee')
                 LIMIT 1"
            );
            $roleStatement->execute(['code' => $roleCode]);
            $roleId = (int) ($roleStatement->fetchColumn() ?: 0);
            if ($roleId <= 0) {
                throw new \RuntimeException('Please select a valid role.');
            }

            $branchIds = array_values(array_unique(array_map('intval', $branchIds)));
            if ($defaultBranchId <= 0 || ! in_array($defaultBranchId, $branchIds, true)) {
                throw new \RuntimeException('Default branch must be included in branch access.');
            }

            $placeholders = implode(',', array_fill(0, count($branchIds), '?'));
            $branchStatement = $this->db->prepare(
                'SELECT id
                 FROM branches
                 WHERE is_active = 1
                   AND id IN (' . $placeholders . ')'
            );
            $branchStatement->execute($branchIds);
            $validBranchIds = array_map('intval', $branchStatement->fetchAll(\PDO::FETCH_COLUMN) ?: []);
            sort($validBranchIds);
            $expectedBranchIds = $branchIds;
            sort($expectedBranchIds);
            if ($validBranchIds !== $expectedBranchIds) {
                throw new \RuntimeException('Branch access contains an inactive or invalid branch.');
            }

            $userStatement = $this->db->prepare(
                'UPDATE users
                 SET role_id = :role_id,
                     default_branch_id = :default_branch_id,
                     session_version = session_version + 1
                 WHERE id = :id'
            );
            $userStatement->execute([
                'id' => $userId,
                'role_id' => $roleId,
                'default_branch_id' => $defaultBranchId,
            ]);

            $deleteStatement = $this->db->prepare('DELETE FROM user_branch_access WHERE user_id = :user_id');
            $deleteStatement->execute(['user_id' => $userId]);

            $insertStatement = $this->db->prepare(
                'INSERT INTO user_branch_access (user_id, branch_id)
                 VALUES (:user_id, :branch_id)'
            );
            foreach ($branchIds as $branchId) {
                $insertStatement->execute([
                    'user_id' => $userId,
                    'branch_id' => $branchId,
                ]);
            }
        });
    }
}
