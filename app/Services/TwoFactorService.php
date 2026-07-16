<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\Auth;
use App\Helpers\AuditLog;
use App\Helpers\Crypto;
use App\Helpers\Session;
use App\Repositories\SecurityThrottleRepository;
use App\Repositories\TwoFactorRecoveryCodeRepository;
use App\Repositories\UserRepository;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use PragmaRX\Google2FA\Google2FA;

final class TwoFactorService extends Service
{
    private function google2fa(): Google2FA
    {
        if (! class_exists(Google2FA::class)) {
            throw new \RuntimeException('2FA library is missing on the server. Upload the vendor/pragmarx and vendor/paragonie folders.');
        }

        return new Google2FA();
    }

    private function renderQrSvg(string $otpAuthUrl): string
    {
        try {
            if (
                ! class_exists(Writer::class)
                || ! class_exists(ImageRenderer::class)
                || ! class_exists(RendererStyle::class)
                || ! class_exists(SvgImageBackEnd::class)
            ) {
                app_write_log('app.2fa.qr_unavailable', 'QR renderer is unavailable; falling back to manual secret only.', [
                    'user_id' => Auth::id(),
                ]);

                return '';
            }

            return (new Writer(new ImageRenderer(
                new RendererStyle(220),
                new SvgImageBackEnd()
            )))->writeString($otpAuthUrl);
        } catch (\Throwable $exception) {
            app_log_exception($exception, 'app.2fa.qr_render_failed');

            return '';
        }
    }

    public function currentVerifyLockState(int $userId, string $ipAddress): ?array
    {
        $throttle = new SecurityThrottleRepository($this->app);
        $maxAttempts = (int) config('security.otp.max_attempts', 3);
        $windowMinutes = (int) config('security.otp.window_minutes', 15);

        return $throttle->currentLockState('otp_verify', 'user:' . $userId, $ipAddress, $windowMinutes, $maxAttempts);
    }

    public function beginSetup(int $userId): array
    {
        $users = new UserRepository($this->app);
        $user = $users->findById($userId);

        if ($user === null) {
            throw new \RuntimeException('User not found.');
        }

        $secret = Crypto::decrypt($user['two_factor_secret_pending_encrypted'] ?? null);

        if ($secret === null) {
            $google2fa = $this->google2fa();
            $secret = $google2fa->generateSecretKey();
            $users->storePendingTwoFactorSecret($userId, Crypto::encrypt($secret));

            AuditLog::record($this->app, 'auth.2fa.setup.started', [
                'user_id' => $userId,
            ]);
        }

        $issuer = (string) config('app.name', 'Travel Agency Operations');
        $username = trim((string) ($user['username'] ?? ''));
        $email = trim((string) ($user['email'] ?? ''));
        $label = $username !== '' && $email !== '' ? $username . ' / ' . $email : ($username !== '' ? $username : $email);
        $otpAuthUrl = $this->google2fa()->getQRCodeUrl($issuer, $label, $secret);
        $qrSvg = $this->renderQrSvg($otpAuthUrl);

        return [
            'secret' => $secret,
            'qr_svg' => $qrSvg,
        ];
    }

    public function completeSetup(int $userId, string $otp, string $ipAddress): array
    {
        $check = $this->assertOtpAllowed($userId, $ipAddress);
        if (! $check['success']) {
            return $check;
        }

        $users = new UserRepository($this->app);
        $user = $users->findById($userId);
        $secret = Crypto::decrypt($user['two_factor_secret_pending_encrypted'] ?? null);

        if ($user === null || $secret === null) {
            return ['success' => false, 'message' => '2FA setup is not ready. Please start again.'];
        }

        if (! $this->google2fa()->verifyKey($secret, trim($otp))) {
            return $this->handleOtpFailure($userId, $ipAddress, 'setup');
        }

        $backupCodes = $this->generateRecoveryCodes();
        $users->activateTwoFactorSecret($userId, Crypto::encrypt($secret));
        (new TwoFactorRecoveryCodeRepository($this->app))->replaceCodes($userId, $backupCodes);
        $this->clearOtpFailures($userId, $ipAddress);

        AuditLog::record($this->app, 'auth.2fa.setup.completed', [
            'user_id' => $userId,
        ]);
        AuditLog::record($this->app, 'auth.2fa.backup_codes.generated', [
            'user_id' => $userId,
        ]);

        Session::put('_two_factor_backup_codes', $backupCodes);
        $freshUser = $users->findForSession($userId);
        $sessionData = SecuritySettingsService::buildSessionData($this->app, $freshUser, true);
        Auth::refresh($sessionData->toArray(), true);

        return ['success' => true];
    }

    public function verifyChallenge(int $userId, string $code, string $ipAddress, bool $rememberDevice = false): array
    {
        $check = $this->assertOtpAllowed($userId, $ipAddress);
        if (! $check['success']) {
            return $check;
        }

        $users = new UserRepository($this->app);
        $user = $users->findById($userId);
        $secret = Crypto::decrypt($user['two_factor_secret_encrypted'] ?? null);
        $trimmedCode = trim($code);
        $normalized = strtoupper(str_replace('-', '', $trimmedCode));

        $verified = false;
        $usedBackupCode = false;

        if ($secret !== null && preg_match('/^\d{6}$/', $normalized) === 1) {
            $verified = $this->google2fa()->verifyKey($secret, $normalized);
        }

        if (! $verified) {
            $backupRepo = new TwoFactorRecoveryCodeRepository($this->app);
            $usedBackupCode = $backupRepo->consumeMatchingCode($userId, $trimmedCode);
            $verified = $usedBackupCode;
        }

        if (! $verified) {
            return $this->handleOtpFailure($userId, $ipAddress, 'verify');
        }

        $this->clearOtpFailures($userId, $ipAddress);

        if ($usedBackupCode) {
            AuditLog::record($this->app, 'auth.2fa.backup_code.used', [
                'user_id' => $userId,
                'ip_address' => $ipAddress,
            ]);
        }

        if ($rememberDevice && ! \App\Helpers\Auth::isSuperAdmin()) {
            try {
                (new TrustedDeviceService($this->app))->createForCurrentUser($userId);
            } catch (\Throwable $exception) {
                app_log_exception($exception, 'auth.2fa.remember_device_failed');
                app_write_log('auth.2fa.remember_device_context', 'Trusted device creation failed after successful OTP verification.', [
                    'user_id' => $userId,
                    'ip_address' => $ipAddress,
                ]);
            }
        }

        $freshUser = $users->findForSession($userId);
        $sessionData = SecuritySettingsService::buildSessionData($this->app, $freshUser, true);
        Auth::refresh($sessionData->toArray(), true);

        return ['success' => true];
    }

    public function resetForReEnrollment(int $userId): array
    {
        $users = new UserRepository($this->app);
        $users->resetTwoFactor($userId);
        (new TwoFactorRecoveryCodeRepository($this->app))->replaceCodes($userId, []);
        (new TrustedDeviceService($this->app))->revokeAllForUser($userId, '2fa_reset', $userId);

        AuditLog::record($this->app, 'auth.2fa.reset', [
            'user_id' => $userId,
            'reason' => 'self_reenroll',
        ]);

        return $users->findForSession($userId);
    }

    public function regenerateBackupCodes(int $userId): array
    {
        $codes = $this->generateRecoveryCodes();
        (new TwoFactorRecoveryCodeRepository($this->app))->replaceCodes($userId, $codes);

        AuditLog::record($this->app, 'auth.2fa.backup_codes.regenerated', [
            'user_id' => $userId,
        ]);

        return $codes;
    }

    public function adminResetTwoFactor(int $adminUserId, int $targetUserId): void
    {
        $users = new UserRepository($this->app);
        $users->resetTwoFactor($targetUserId);
        (new TwoFactorRecoveryCodeRepository($this->app))->replaceCodes($targetUserId, []);
        (new TrustedDeviceService($this->app))->revokeAllForUser($targetUserId, '2fa_reset_by_admin', $adminUserId);

        AuditLog::record($this->app, 'auth.2fa.reset_by_admin', [
            'user_id' => $adminUserId,
            'target_user_id' => $targetUserId,
        ]);
    }

    public function consumeBackupCodesForDisplay(): array
    {
        $codes = Session::get('_two_factor_backup_codes', []);
        Session::forget('_two_factor_backup_codes');

        return is_array($codes) ? $codes : [];
    }

    private function assertOtpAllowed(int $userId, string $ipAddress): array
    {
        $throttle = new SecurityThrottleRepository($this->app);
        $subjectKey = 'user:' . $userId;
        $maxAttempts = (int) config('security.otp.max_attempts', 3);
        $windowMinutes = (int) config('security.otp.window_minutes', 15);

        if ($throttle->countRecentFailures('otp_verify', $subjectKey, $ipAddress, $windowMinutes) >= $maxAttempts) {
            return [
                'success' => false,
                'message' => 'Too many OTP failures. Please wait and try again.',
                'lock_state' => $throttle->currentLockState('otp_verify', $subjectKey, $ipAddress, $windowMinutes, $maxAttempts),
            ];
        }

        return ['success' => true];
    }

    private function recordOtpFailure(int $userId, string $ipAddress, string $context): void
    {
        (new SecurityThrottleRepository($this->app))->record('otp_verify', 'user:' . $userId, $ipAddress, false);
        AuditLog::record($this->app, 'auth.2fa.otp.failure', [
            'user_id' => $userId,
            'ip_address' => $ipAddress,
            'context' => $context,
        ]);
    }

    private function clearOtpFailures(int $userId, string $ipAddress): void
    {
        (new SecurityThrottleRepository($this->app))->clearFailures('otp_verify', 'user:' . $userId, $ipAddress);
    }

    private function handleOtpFailure(int $userId, string $ipAddress, string $context): array
    {
        $this->recordOtpFailure($userId, $ipAddress, $context);
        $failures = (new SecurityThrottleRepository($this->app))->countRecentFailures(
            'otp_verify',
            'user:' . $userId,
            $ipAddress,
            (int) config('security.otp.window_minutes', 15)
        );

        if ($failures >= (int) config('security.otp.max_attempts', 3)) {
            return [
                'success' => false,
                'message' => 'Too many OTP failures. Please wait and try again.',
                'lock_state' => $this->currentVerifyLockState($userId, $ipAddress),
            ];
        }

        return ['success' => false, 'message' => 'Invalid verification code.'];
    }

    private function generateRecoveryCodes(): array
    {
        $codes = [];

        for ($i = 0; $i < 8; $i++) {
            $raw = strtoupper(bin2hex(random_bytes(4)));
            $codes[] = substr($raw, 0, 4) . '-' . substr($raw, 4, 4);
        }

        return $codes;
    }
}
