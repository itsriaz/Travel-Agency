<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\UserSessionData;
use App\Helpers\AuditLog;
use App\Helpers\PasswordPolicy;
use App\Repositories\AuditLogRepository;
use App\Repositories\TrustedDeviceRepository;
use App\Repositories\UserRepository;
use RuntimeException;

final class SecuritySettingsService extends Service
{
    public function userSummary(int $userId): array
    {
        $users = new UserRepository($this->app);

        return [
            'user' => $users->findById($userId),
            'trusted_devices' => (new TrustedDeviceRepository($this->app))->listForUser($userId),
            'failure_summary' => (new AuditLogRepository($this->app))->recentFailureSummaryForUser($userId),
            'events' => (new AuditLogRepository($this->app))->relevantEventsForUser($userId),
        ];
    }

    public function userOptions(): array
    {
        return (new UserRepository($this->app))->listUsers(true);
    }

    public function adminTargetSummary(int $userId): array
    {
        $users = new UserRepository($this->app);
        $summary = $this->userSummary($userId);
        $roleCode = (string) (($summary['user']['role_code'] ?? '') ?: '');
        $summary['branch_access_ids'] = $users->branchIdsForUser($userId, $roleCode);

        return $summary;
    }

    public function adminRoleBranchOptions(): array
    {
        $users = new UserRepository($this->app);

        return [
            'roles' => $users->listAssignableRoles(),
            'branches' => $users->listActiveBranches(),
        ];
    }

    public function adminUpdateRoleAndBranchAccess(int $actorUserId, array $input): int
    {
        $targetUserId = (int) ($input['target_user_id'] ?? 0);
        $roleCode = mb_strtolower(trim((string) ($input['role_code'] ?? '')));
        $defaultBranchId = (int) ($input['default_branch_id'] ?? 0);
        $branchIds = array_map('intval', (array) ($input['branch_ids'] ?? []));

        if ($targetUserId <= 0) {
            throw new RuntimeException('Please select a user.');
        }

        if (! in_array($roleCode, ['super_admin', 'branch_admin', 'employee'], true)) {
            throw new RuntimeException('Please select a valid role.');
        }

        $branchIds = array_values(array_unique(array_filter($branchIds, static fn (int $id): bool => $id > 0)));
        if ($branchIds === []) {
            throw new RuntimeException('Select at least one branch.');
        }

        if (! in_array($defaultBranchId, $branchIds, true)) {
            throw new RuntimeException('Default branch must be selected in branch access.');
        }

        $users = new UserRepository($this->app);
        $target = $users->findById($targetUserId);
        if ($target === null) {
            throw new RuntimeException('The selected user was not found.');
        }

        if ($targetUserId === $actorUserId && $roleCode !== 'super_admin') {
            throw new RuntimeException('You cannot remove your own super admin role.');
        }

        $users->updateRoleAndBranchAccess($targetUserId, $roleCode, $defaultBranchId, $branchIds);
        $actor = $users->findById($actorUserId);

        AuditLog::record($this->app, 'auth.super_admin.role_branch_access_updated', [
            'user_id' => $actorUserId,
            'actor_username' => (string) ($actor['username'] ?? ''),
            'target_user_id' => $targetUserId,
            'target_username' => (string) ($target['username'] ?? ''),
            'role_code' => $roleCode,
            'default_branch_id' => $defaultBranchId,
            'branch_ids' => $branchIds,
        ]);

        return $targetUserId;
    }

    public function adminCreateUser(int $actorUserId, array $input): int
    {
        $name = trim((string) ($input['name'] ?? ''));
        $username = mb_strtolower(trim((string) ($input['username'] ?? '')));
        $email = mb_strtolower(trim((string) ($input['email'] ?? '')));
        $temporaryPassword = (string) ($input['temporary_password'] ?? '');
        $roleCode = mb_strtolower(trim((string) ($input['role_code'] ?? 'employee')));
        $defaultBranchId = (int) ($input['default_branch_id'] ?? 0);
        $branchIds = array_map('intval', (array) ($input['branch_ids'] ?? []));

        if ($name === '') {
            throw new RuntimeException('Name is required.');
        }

        if ($username === '' || preg_match('/^[a-z0-9._-]{3,100}$/', $username) !== 1) {
            throw new RuntimeException('Username must be 3-100 characters and use only letters, numbers, dot, underscore, or dash.');
        }

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('A valid email address is required.');
        }

        if (! in_array($roleCode, ['super_admin', 'branch_admin', 'employee'], true)) {
            throw new RuntimeException('Please select a valid role.');
        }

        $errors = PasswordPolicy::validate($temporaryPassword, $temporaryPassword);
        if ($errors !== []) {
            throw new RuntimeException($errors[0]);
        }

        $users = new UserRepository($this->app);
        if ($users->usernameOrEmailExists($username, $email)) {
            throw new RuntimeException('Username or email already exists.');
        }

        $userId = $users->createUser(
            $name,
            $username,
            $email,
            $temporaryPassword,
            $roleCode,
            $defaultBranchId,
            $branchIds
        );

        $actor = $users->findById($actorUserId);
        $created = $users->findById($userId);

        AuditLog::record($this->app, 'auth.super_admin.user_created', [
            'user_id' => $actorUserId,
            'actor_username' => (string) ($actor['username'] ?? ''),
            'target_user_id' => $userId,
            'target_username' => (string) ($created['username'] ?? $username),
            'target_role_code' => $roleCode,
            'default_branch_id' => $defaultBranchId,
            'branch_ids' => array_values(array_unique($branchIds)),
        ]);

        return $userId;
    }

    public function adminUpdateUserIdentity(int $actorUserId, array $input): int
    {
        $targetUserId = (int) ($input['target_user_id'] ?? 0);
        $name = trim((string) ($input['name'] ?? ''));
        $username = mb_strtolower(trim((string) ($input['username'] ?? '')));
        $email = mb_strtolower(trim((string) ($input['email'] ?? '')));

        if ($targetUserId <= 0) {
            throw new RuntimeException('Please select a user.');
        }

        if ($name === '') {
            throw new RuntimeException('Name is required.');
        }

        if ($username === '' || preg_match('/^[a-z0-9._-]{3,100}$/', $username) !== 1) {
            throw new RuntimeException('Username must be 3-100 characters and use only letters, numbers, dot, underscore, or dash.');
        }

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('A valid email address is required.');
        }

        $users = new UserRepository($this->app);
        $target = $users->findById($targetUserId);
        if ($target === null) {
            throw new RuntimeException('The selected user was not found.');
        }

        if ($users->usernameOrEmailExistsForOtherUser($targetUserId, $username, $email)) {
            throw new RuntimeException('Username or email already exists.');
        }

        $before = [
            'name' => (string) ($target['name'] ?? ''),
            'username' => (string) ($target['username'] ?? ''),
            'email' => (string) ($target['email'] ?? ''),
        ];

        $users->updateIdentityFields($targetUserId, $name, $username, $email);
        $actor = $users->findById($actorUserId);

        AuditLog::record($this->app, 'auth.super_admin.user_identity_updated', [
            'user_id' => $actorUserId,
            'actor_username' => (string) ($actor['username'] ?? ''),
            'target_user_id' => $targetUserId,
            'target_username_before' => $before['username'],
            'target_username_after' => $username,
            'before' => $before,
            'after' => [
                'name' => $name,
                'username' => $username,
                'email' => $email,
            ],
        ]);

        return $targetUserId;
    }

    public function adminSetUserActiveStatus(int $actorUserId, int $targetUserId, bool $isActive): int
    {
        $users = new UserRepository($this->app);
        $actor = $users->findById($actorUserId);
        $target = $users->findById($targetUserId);

        if ($target === null) {
            throw new RuntimeException('The selected user was not found.');
        }

        if ($targetUserId === $actorUserId && ! $isActive) {
            throw new RuntimeException('You cannot deactivate your own account.');
        }

        if (! $isActive && (string) ($target['role_code'] ?? '') === 'super_admin' && $users->countActiveUsersByRoleCode('super_admin') <= 1) {
            throw new RuntimeException('At least one active super admin must remain.');
        }

        $users->setUserActiveStatus($targetUserId, $isActive);

        AuditLog::record($this->app, $isActive ? 'auth.super_admin.user_reactivated' : 'auth.super_admin.user_deactivated', [
            'user_id' => $actorUserId,
            'actor_username' => (string) ($actor['username'] ?? ''),
            'target_user_id' => $targetUserId,
            'target_username' => (string) ($target['username'] ?? ''),
            'target_role_code' => (string) ($target['role_code'] ?? ''),
        ]);

        return $targetUserId;
    }

    public function logoutAllDevices(int $userId): void
    {
        $users = new UserRepository($this->app);
        $users->incrementSessionVersion($userId);
        (new TrustedDeviceService($this->app))->revokeAllForUser($userId, 'logout_all_devices', $userId);

        $freshUser = $users->findForSession($userId);
        $sessionData = self::buildSessionData($this->app, $freshUser, true);
        \App\Helpers\Auth::refresh($sessionData->toArray(), true);

        AuditLog::record($this->app, 'auth.sessions.revoked', [
            'user_id' => $userId,
        ]);
    }

    public static function buildSessionData(\App\Core\App $app, array $user, bool $twoFactorVerified): UserSessionData
    {
        $users = new UserRepository($app);
        $accessibleBranchIds = array_map('intval', $users->branchIdsForUser((int) $user['id'], $user['role_code']));
        $branchOptions = (new \App\Repositories\BookingRepository($app))->branchOptions($accessibleBranchIds);
        $activeBranchId = (new BranchContextService($app))->resolveLoginActiveBranchId(
            (int) $user['default_branch_id'],
            $accessibleBranchIds
        );
        $branchNameById = [];
        foreach ($branchOptions as $branchOption) {
            $branchId = (int) ($branchOption['id'] ?? 0);
            if ($branchId <= 0) {
                continue;
            }

            $branchNameById[$branchId] = (string) ($branchOption['name'] ?? '');
        }
        $defaultBranchId = (int) ($user['default_branch_id'] ?? 0);
        $activeBranchName = (string) ($branchNameById[$activeBranchId] ?? '');
        $defaultBranchName = (string) ($branchNameById[$defaultBranchId] ?? ($user['default_branch_name'] ?? ''));
        $accessibleBranchNames = [];
        foreach ($accessibleBranchIds as $branchId) {
            if (isset($branchNameById[$branchId]) && trim((string) $branchNameById[$branchId]) !== '') {
                $accessibleBranchNames[] = (string) $branchNameById[$branchId];
            }
        }

        return new UserSessionData(
            (int) $user['id'],
            $user['name'],
            $user['username'],
            $user['email'],
            $user['role_code'],
            $activeBranchId,
            $activeBranchName,
            $accessibleBranchIds,
            $accessibleBranchNames,
            $defaultBranchId,
            $defaultBranchName,
            (bool) $user['must_change_password'],
            (int) $user['session_version'],
            (bool) $user['two_factor_enabled'],
            $twoFactorVerified
        );
    }
}
