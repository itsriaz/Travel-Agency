<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\UserSessionData;
use App\Helpers\Auth;
use App\Helpers\AuditLog;
use App\Repositories\LoginAttemptRepository;
use App\Repositories\UserRepository;
use App\Services\TrustedDeviceService;

final class AuthService extends Service
{
    public function attempt(string $login, string $password, string $ipAddress): array
    {
        $loginKey = mb_strtolower(trim($login));
        $attempts = new LoginAttemptRepository($this->app);
        $maxAttempts = (int) config('security.login.max_attempts', 5);
        $windowMinutes = (int) config('security.login.window_minutes', 15);

        if ($attempts->countRecentFailures($loginKey, $ipAddress, $windowMinutes) >= $maxAttempts) {
            AuditLog::record($this->app, 'auth.login.locked', [
                'login' => $loginKey,
                'ip_address' => $ipAddress,
            ]);

            return [
                'success' => false,
                'message' => 'Too many login attempts. Please wait and try again.',
            ];
        }

        $userRepository = new UserRepository($this->app);
        $user = $userRepository->findForLogin($loginKey);

        if ($user === null || ! password_verify($password, $user['password_hash'])) {
            $attempts->record($loginKey, $ipAddress, false, $user['id'] ?? null);
            $failureCount = $attempts->countRecentFailures($loginKey, $ipAddress, $windowMinutes);

            AuditLog::record($this->app, 'auth.login.failed', [
                'login' => $loginKey,
                'user_id' => $user['id'] ?? null,
                'ip_address' => $ipAddress,
            ]);

            if ($failureCount >= $maxAttempts) {
                AuditLog::record($this->app, 'auth.login.locked', [
                    'login' => $loginKey,
                    'user_id' => $user['id'] ?? null,
                    'ip_address' => $ipAddress,
                ]);

                return [
                    'success' => false,
                    'message' => 'Too many login attempts. Please wait and try again.',
                ];
            }

            return [
                'success' => false,
                'message' => 'Invalid credentials.',
            ];
        }

        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            $userRepository->rehashPassword((int) $user['id'], $password);
        }

        $attempts->record($loginKey, $ipAddress, true, (int) $user['id']);
        $attempts->clearFailures($loginKey, $ipAddress);
        $userRepository->touchLastLogin((int) $user['id']);

        $sessionData = new UserSessionData(
            (int) $user['id'],
            $user['name'],
            $user['username'],
            $user['email'],
            $user['role_code'],
            (int) $user['default_branch_id'],
            array_map('intval', $userRepository->branchIdsForUser((int) $user['id'], $user['role_code'])),
            (bool) $user['must_change_password'],
            (int) $user['session_version'],
            (bool) $user['two_factor_enabled'],
            false
        );

        Auth::login($sessionData->toArray());

        $twoFactorVerified = false;
        if (! (bool) $user['must_change_password'] && (bool) $user['two_factor_enabled'] && ! Auth::isSuperAdmin()) {
            $twoFactorVerified = (new TrustedDeviceService($this->app))->canBypassOtp((int) $user['id']);

            if ($twoFactorVerified) {
                $sessionData = new UserSessionData(
                    (int) $user['id'],
                    $user['name'],
                    $user['username'],
                    $user['email'],
                    $user['role_code'],
                    (int) $user['default_branch_id'],
                    array_map('intval', $userRepository->branchIdsForUser((int) $user['id'], $user['role_code'])),
                    (bool) $user['must_change_password'],
                    (int) $user['session_version'],
                    (bool) $user['two_factor_enabled'],
                    true
                );
                Auth::refresh($sessionData->toArray(), true);
            }
        }

        AuditLog::record($this->app, 'auth.login', [
            'user_id' => $user['id'],
            'username' => $user['username'],
            'email' => $user['email'],
            'ip_address' => $ipAddress,
        ]);

        if ((bool) $user['must_change_password']) {
            AuditLog::record($this->app, 'auth.password_change.required', [
                'user_id' => $user['id'],
                'reason' => 'first_login_or_reset',
            ]);
        }

        return [
            'success' => true,
            'redirect_to' => (bool) $user['must_change_password']
                ? '/force-password-change'
                : (Auth::twoFactorTemporarilyDisabled()
                    ? ($user['role_code'] === 'super_admin' ? '/' : '/workspace')
                    : ((bool) $user['two_factor_enabled']
                        ? ($twoFactorVerified ? ($user['role_code'] === 'super_admin' ? '/' : '/workspace') : '/2fa/verify')
                        : '/2fa/setup')),
        ];
    }
}
