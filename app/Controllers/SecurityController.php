<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Auth;
use App\Helpers\AuditLog;
use App\Helpers\Csrf;
use App\Helpers\Flash;
use App\Repositories\UserRepository;
use App\Services\PasswordSecurityService;
use App\Services\SecuritySettingsService;
use App\Services\TrustedDeviceService;
use App\Services\TwoFactorService;

final class SecurityController extends BaseController
{
    public function settings(): string
    {
        $service = new SecuritySettingsService($this->app);

        return $this->view('security/settings', [
            'title' => 'Security Settings',
            'summary' => $service->userSummary((int) Auth::id()),
        ]);
    }

    public function revokeTrustedDevice(): never
    {
        Csrf::verifyOrFail($_POST['_token'] ?? null);

        (new TrustedDeviceService($this->app))->revokeDevice(
            (int) Auth::id(),
            (int) ($_POST['device_id'] ?? 0),
            'manual',
            (int) Auth::id()
        );

        Flash::success('Trusted device revoked.');
        $this->redirect('/security');
    }

    public function logoutAllDevices(): never
    {
        Csrf::verifyOrFail($_POST['_token'] ?? null);

        (new SecuritySettingsService($this->app))->logoutAllDevices((int) Auth::id());

        Flash::success('All other sessions and trusted devices were revoked.');
        $this->redirect('/security');
    }

    public function reEnrollTwoFactor(): never
    {
        Csrf::verifyOrFail($_POST['_token'] ?? null);

        $freshUser = (new TwoFactorService($this->app))->resetForReEnrollment((int) Auth::id());
        $sessionData = SecuritySettingsService::buildSessionData($this->app, $freshUser, false);
        Auth::refresh($sessionData->toArray(), true);

        Flash::success('2FA reset. Please enroll again.');
        $this->redirect('/2fa/setup');
    }

    public function regenerateBackupCodes(): never
    {
        Csrf::verifyOrFail($_POST['_token'] ?? null);

        $codes = (new TwoFactorService($this->app))->regenerateBackupCodes((int) Auth::id());
        \App\Helpers\Session::put('_two_factor_backup_codes', $codes);

        Flash::success('Backup codes regenerated. They will be shown once.');
        $this->redirect('/2fa/recovery-codes');
    }

    public function adminPanel(): string
    {
        $service = new SecuritySettingsService($this->app);
        $selectedUserId = (int) ($_GET['user_id'] ?? 0);

        return $this->view('security/admin_panel', [
            'title' => 'Security Controls',
            'users' => $service->userOptions(),
            'selectedUserId' => $selectedUserId,
            'target' => $selectedUserId > 0 ? $service->adminTargetSummary($selectedUserId) : null,
        ]);
    }

    public function adminForcePasswordReset(): never
    {
        Csrf::verifyOrFail($_POST['_token'] ?? null);

        $targetUserId = (int) ($_POST['target_user_id'] ?? 0);
        $temporaryPassword = (string) ($_POST['temporary_password'] ?? '');

        (new PasswordSecurityService($this->app))->adminResetPassword((int) Auth::id(), $targetUserId, $temporaryPassword);
        AuditLog::record($this->app, 'auth.super_admin.security_action', [
            'user_id' => (int) Auth::id(),
            'action' => 'force_password_reset',
            'target_user_id' => $targetUserId,
        ]);

        Flash::success('Password reset forced for the selected user.');
        $this->redirect('/admin/security?user_id=' . $targetUserId);
    }

    public function adminResetTwoFactor(): never
    {
        Csrf::verifyOrFail($_POST['_token'] ?? null);

        $targetUserId = (int) ($_POST['target_user_id'] ?? 0);
        (new TwoFactorService($this->app))->adminResetTwoFactor((int) Auth::id(), $targetUserId);
        AuditLog::record($this->app, 'auth.super_admin.security_action', [
            'user_id' => (int) Auth::id(),
            'action' => 'reset_2fa',
            'target_user_id' => $targetUserId,
        ]);

        Flash::success('2FA reset for the selected user.');
        $this->redirect('/admin/security?user_id=' . $targetUserId);
    }

    public function adminRevokeTrustedDevices(): never
    {
        Csrf::verifyOrFail($_POST['_token'] ?? null);

        $targetUserId = (int) ($_POST['target_user_id'] ?? 0);
        (new TrustedDeviceService($this->app))->revokeAllForUser($targetUserId, 'admin', (int) Auth::id());
        AuditLog::record($this->app, 'auth.super_admin.security_action', [
            'user_id' => (int) Auth::id(),
            'action' => 'revoke_trusted_devices',
            'target_user_id' => $targetUserId,
        ]);

        Flash::success('Trusted devices revoked for the selected user.');
        $this->redirect('/admin/security?user_id=' . $targetUserId);
    }
}
