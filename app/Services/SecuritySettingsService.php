<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\UserSessionData;
use App\Helpers\AuditLog;
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
        return (new UserRepository($this->app))->listActiveUsers();
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

        AuditLog::record($this->app, 'auth.super_admin.role_branch_access_updated', [
            'user_id' => $actorUserId,
            'target_user_id' => $targetUserId,
            'role_code' => $roleCode,
            'default_branch_id' => $defaultBranchId,
            'branch_ids' => $branchIds,
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

        return new UserSessionData(
            (int) $user['id'],
            $user['name'],
            $user['username'],
            $user['email'],
            $user['role_code'],
            (int) $user['default_branch_id'],
            array_map('intval', $users->branchIdsForUser((int) $user['id'], $user['role_code'])),
            (bool) $user['must_change_password'],
            (int) $user['session_version'],
            (bool) $user['two_factor_enabled'],
            $twoFactorVerified
        );
    }
}
