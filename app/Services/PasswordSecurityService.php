<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\AuditLog;
use App\Helpers\PasswordPolicy;
use App\Repositories\PasswordResetTokenRepository;
use App\Repositories\SecurityThrottleRepository;
use App\Repositories\UserRepository;
use App\Services\TrustedDeviceService;

final class PasswordSecurityService extends Service
{
    public function changeCurrentPassword(int $userId, string $currentPassword, string $newPassword, string $confirmation): array
    {
        $errors = PasswordPolicy::validate($newPassword, $confirmation);

        if ($errors !== []) {
            return ['success' => false, 'message' => $errors[0]];
        }

        $users = new UserRepository($this->app);
        $user = $users->findById($userId);

        if ($user === null || ! password_verify($currentPassword, $user['password_hash'])) {
            return ['success' => false, 'message' => 'Current password is incorrect.'];
        }

        $users->updatePassword($userId, $newPassword, false, null);
        (new TrustedDeviceService($this->app))->revokeAllForUser($userId, 'password_change', $userId);
        $freshUser = $users->findForSession($userId);

        AuditLog::record($this->app, 'auth.password.changed', [
            'user_id' => $userId,
            'context' => 'forced_change',
        ]);

        return [
            'success' => true,
            'user' => $freshUser,
        ];
    }

    public function requestForgotPassword(string $login, string $ipAddress): void
    {
        $subjectKey = mb_strtolower(trim($login));
        $throttle = new SecurityThrottleRepository($this->app);
        $maxAttempts = (int) config('security.forgot.max_attempts', 5);
        $windowMinutes = (int) config('security.forgot.window_minutes', 30);

        if ($throttle->countRecent('forgot_password', $subjectKey, $ipAddress, $windowMinutes) >= $maxAttempts) {
            AuditLog::record($this->app, 'auth.forgot_password.locked', [
                'login' => $subjectKey,
                'ip_address' => $ipAddress,
            ]);
            return;
        }

        $throttle->record('forgot_password', $subjectKey, $ipAddress, true);

        $users = new UserRepository($this->app);
        $user = $users->findForLogin($subjectKey);

        AuditLog::record($this->app, 'auth.forgot_password.requested', [
            'user_id' => $user['id'] ?? null,
            'login' => $subjectKey,
            'ip_address' => $ipAddress,
        ]);

        if ($user === null) {
            return;
        }

        $selector = bin2hex(random_bytes(8));
        $token = bin2hex(random_bytes(32));
        $tokenHash = password_hash($token, PASSWORD_DEFAULT);

        $tokens = new PasswordResetTokenRepository($this->app);
        $tokens->invalidateUnusedForUser((int) $user['id']);
        $tokens->create(
            (int) $user['id'],
            $selector,
            $tokenHash,
            $ipAddress,
            (int) config('security.reset.token_ttl_minutes', 60)
        );

        $resetUrl = url('/reset-password') . '?selector=' . rawurlencode($selector) . '&token=' . rawurlencode($token);
        (new PasswordResetDeliveryService($this->app))->deliver($subjectKey, $resetUrl);
    }

    public function completeForgotPassword(
        string $selector,
        string $token,
        string $newPassword,
        string $confirmation,
        string $ipAddress
    ): array {
        $subjectKey = mb_strtolower(trim($selector));
        $throttle = new SecurityThrottleRepository($this->app);
        $maxAttempts = (int) config('security.reset.max_attempts', 5);
        $windowMinutes = (int) config('security.reset.window_minutes', 30);

        if ($throttle->countRecent('password_reset_submit', $subjectKey, $ipAddress, $windowMinutes) >= $maxAttempts) {
            return ['success' => false, 'message' => 'Too many reset attempts. Please try again later.'];
        }

        $throttle->record('password_reset_submit', $subjectKey, $ipAddress, true);

        $errors = PasswordPolicy::validate($newPassword, $confirmation);
        if ($errors !== []) {
            return ['success' => false, 'message' => $errors[0]];
        }

        $tokens = new PasswordResetTokenRepository($this->app);
        $record = $tokens->findActiveBySelector($selector);

        if (
            $record === null ||
            $record['used_at'] !== null ||
            strtotime((string) $record['expires_at']) < time() ||
            ! password_verify($token, (string) $record['token_hash'])
        ) {
            AuditLog::record($this->app, 'auth.reset.invalid_or_expired', [
                'selector' => $selector,
                'ip_address' => $ipAddress,
            ]);

            return ['success' => false, 'message' => 'This reset link is invalid or expired.'];
        }

        $users = new UserRepository($this->app);
        $users->updatePassword((int) $record['user_id'], $newPassword, false, null);
        (new TrustedDeviceService($this->app))->revokeAllForUser((int) $record['user_id'], 'password_reset', (int) $record['user_id']);
        $tokens->markUsed((int) $record['id']);

        AuditLog::record($this->app, 'auth.forgot_password.completed', [
            'user_id' => (int) $record['user_id'],
            'ip_address' => $ipAddress,
        ]);

        return ['success' => true];
    }

    public function adminResetPassword(int $adminUserId, int $targetUserId, string $temporaryPassword): void
    {
        $errors = PasswordPolicy::validate($temporaryPassword, $temporaryPassword);

        if ($errors !== []) {
            throw new \InvalidArgumentException($errors[0]);
        }

        $users = new UserRepository($this->app);
        $users->updatePassword($targetUserId, $temporaryPassword, true, 'admin_reset');
        (new TrustedDeviceService($this->app))->revokeAllForUser($targetUserId, 'admin_password_reset', $adminUserId);

        AuditLog::record($this->app, 'auth.admin_password_reset', [
            'user_id' => $adminUserId,
            'target_user_id' => $targetUserId,
        ]);
    }
}
