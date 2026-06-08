<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Helpers\PasswordHasher;

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
            'password_hash' => PasswordHasher::make($password),
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
            'password_hash' => PasswordHasher::make($password),
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

    public function listUsers(bool $includeInactive = false): array
    {
        $sql = 'SELECT u.id, u.name, u.username, u.email, u.is_active, u.default_branch_id,
                       r.code AS role_code, r.name AS role_name, b.name AS default_branch_name
                FROM users u
                INNER JOIN roles r ON r.id = u.role_id
                INNER JOIN branches b ON b.id = u.default_branch_id';

        if (! $includeInactive) {
            $sql .= ' WHERE u.is_active = 1';
        }

        $sql .= ' ORDER BY u.is_active DESC, u.name ASC, u.id ASC';

        $statement = $this->db->query($sql);

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

    public function createUser(
        string $name,
        string $username,
        string $email,
        string $password,
        string $roleCode,
        int $defaultBranchId,
        array $branchIds
    ): int {
        return $this->transaction(function () use ($name, $username, $email, $password, $roleCode, $defaultBranchId, $branchIds): int {
            $roleId = $this->roleIdByCode($roleCode);
            if ($roleId <= 0) {
                throw new \RuntimeException('Please select a valid role.');
            }

            $branchIds = $this->validatedActiveBranchIds($defaultBranchId, $branchIds);

            $statement = $this->db->prepare(
                'INSERT INTO users (
                    role_id, default_branch_id, name, username, email, password_hash, is_active,
                    must_change_password, force_password_change_reason, password_changed_at
                 ) VALUES (
                    :role_id, :default_branch_id, :name, :username, :email, :password_hash, 1,
                    1, :force_password_change_reason, NULL
                 )'
            );
            $statement->execute([
                'role_id' => $roleId,
                'default_branch_id' => $defaultBranchId,
                'name' => $name,
                'username' => $username,
                'email' => $email,
                'password_hash' => PasswordHasher::make($password),
                'force_password_change_reason' => 'first_login',
            ]);

            $userId = (int) $this->db->lastInsertId();
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

            return $userId;
        });
    }

    public function setUserActiveStatus(int $userId, bool $isActive): void
    {
        $statement = $this->db->prepare(
            'UPDATE users
             SET is_active = :is_active,
                 session_version = session_version + 1
             WHERE id = :id'
        );
        $statement->execute([
            'id' => $userId,
            'is_active' => $isActive ? 1 : 0,
        ]);
    }

    public function countActiveUsersByRoleCode(string $roleCode): int
    {
        $statement = $this->db->prepare(
            'SELECT COUNT(*)
             FROM users u
             INNER JOIN roles r ON r.id = u.role_id
             WHERE r.code = :role_code
               AND u.is_active = 1'
        );
        $statement->execute(['role_code' => $roleCode]);

        return (int) $statement->fetchColumn();
    }

    public function usernameOrEmailExists(string $username, string $email): bool
    {
        $statement = $this->db->prepare(
            'SELECT 1
             FROM users
             WHERE LOWER(username) = :username
                OR LOWER(email) = :email
             LIMIT 1'
        );
        $statement->execute([
            'username' => mb_strtolower($username),
            'email' => mb_strtolower($email),
        ]);

        return $statement->fetchColumn() !== false;
    }

    public function usernameOrEmailExistsForOtherUser(int $userId, string $username, string $email): bool
    {
        $statement = $this->db->prepare(
            'SELECT 1
             FROM users
             WHERE id <> :user_id
               AND (
                   LOWER(username) = :username
                   OR LOWER(email) = :email
               )
             LIMIT 1'
        );
        $statement->execute([
            'user_id' => $userId,
            'username' => mb_strtolower($username),
            'email' => mb_strtolower($email),
        ]);

        return $statement->fetchColumn() !== false;
    }

    public function updateIdentityFields(int $userId, string $name, string $username, string $email): void
    {
        $statement = $this->db->prepare(
            'UPDATE users
             SET name = :name,
                 username = :username,
                 email = :email,
                 session_version = session_version + 1
             WHERE id = :id'
        );
        $statement->execute([
            'id' => $userId,
            'name' => $name,
            'username' => $username,
            'email' => $email,
        ]);
    }

    private function roleIdByCode(string $roleCode): int
    {
        $statement = $this->db->prepare(
            "SELECT id
             FROM roles
             WHERE code = :code
               AND code IN ('super_admin', 'branch_admin', 'employee')
             LIMIT 1"
        );
        $statement->execute(['code' => $roleCode]);

        return (int) ($statement->fetchColumn() ?: 0);
    }

    private function validatedActiveBranchIds(int $defaultBranchId, array $branchIds): array
    {
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

        return $branchIds;
    }
}
