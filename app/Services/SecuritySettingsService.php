<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\UserSessionData;
use App\Helpers\AuditLog;
use App\Repositories\AuditLogRepository;
use App\Repositories\TrustedDeviceRepository;
use App\Repositories\UserRepository;

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
        return $this->userSummary($userId);
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
