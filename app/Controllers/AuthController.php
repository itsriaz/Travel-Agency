<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Auth;
use App\Helpers\Csrf;
use App\Helpers\Flash;
use App\Services\AuthService;
use App\Services\PasswordSecurityService;
use App\Services\TwoFactorService;

final class AuthController extends BaseController
{
    private function postLoginTarget(): string
    {
        return Auth::isSuperAdmin() ? '/' : '/workspace';
    }

    public function showLogin(): string
    {
        return $this->view('auth/login', [
            'title' => 'Sign In',
        ], 'layouts/guest');
    }

    public function showForgotPassword(): string
    {
        return $this->view('auth/forgot_password', [
            'title' => 'Forgot Password',
        ], 'layouts/guest');
    }

    public function forgotPassword(): string
    {
        Csrf::verifyOrFail($_POST['_token'] ?? null);

        $login = trim((string) ($_POST['login'] ?? ''));
        $ipAddress = (string) ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');

        (new PasswordSecurityService($this->app))->requestForgotPassword($login, $ipAddress);

        Flash::success('If the account exists, reset instructions have been generated.');

        return $this->showForgotPassword();
    }

    public function showResetPassword(): string
    {
        return $this->view('auth/reset_password', [
            'title' => 'Reset Password',
            'selector' => (string) ($_GET['selector'] ?? ''),
            'token' => (string) ($_GET['token'] ?? ''),
        ], 'layouts/guest');
    }

    public function resetPassword(): string
    {
        Csrf::verifyOrFail($_POST['_token'] ?? null);

        $result = (new PasswordSecurityService($this->app))->completeForgotPassword(
            trim((string) ($_POST['selector'] ?? '')),
            trim((string) ($_POST['token'] ?? '')),
            (string) ($_POST['password'] ?? ''),
            (string) ($_POST['password_confirmation'] ?? ''),
            (string) ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1')
        );

        if (! $result['success']) {
            Flash::error($result['message']);
            return $this->showResetPassword();
        }

        Flash::success('Password has been reset. You can now sign in.');
        $this->redirect('/login');
    }

    public function showForcePasswordChange(): string
    {
        if (! Auth::mustChangePassword()) {
            $this->redirect(
                Auth::twoFactorTemporarilyDisabled()
                    ? $this->postLoginTarget()
                    : (Auth::twoFactorEnabled() ? '/2fa/verify' : '/2fa/setup')
            );
        }

        return $this->view('auth/force_password_change', [
            'title' => 'Change Password',
        ]);
    }

    public function forcePasswordChange(): string
    {
        if (! Auth::mustChangePassword()) {
            $this->redirect('/');
        }

        Csrf::verifyOrFail($_POST['_token'] ?? null);

        $result = (new PasswordSecurityService($this->app))->changeCurrentPassword(
            (int) Auth::id(),
            (string) ($_POST['current_password'] ?? ''),
            (string) ($_POST['password'] ?? ''),
            (string) ($_POST['password_confirmation'] ?? '')
        );

        if (! $result['success']) {
            Flash::error($result['message']);
            return $this->showForcePasswordChange();
        }

        $user = $result['user'];
        $repository = new \App\Repositories\UserRepository($this->app);
        $sessionData = new \App\DTOs\UserSessionData(
            (int) $user['id'],
            $user['name'],
            $user['username'],
            $user['email'],
            $user['role_code'],
            (int) $user['default_branch_id'],
            array_map('intval', $repository->branchIdsForUser((int) $user['id'], $user['role_code'])),
            (bool) $user['must_change_password'],
            (int) $user['session_version'],
            (bool) $user['two_factor_enabled'],
            false
        );
        Auth::refresh($sessionData->toArray(), true);

        Flash::success('Password changed successfully.');
        $this->redirect(
            Auth::twoFactorTemporarilyDisabled()
                ? $this->postLoginTarget()
                : (Auth::twoFactorEnabled() ? '/2fa/verify' : '/2fa/setup')
        );
    }

    public function showTwoFactorSetup(): string
    {
        if (Auth::mustChangePassword()) {
            $this->redirect('/force-password-change');
        }

        if (Auth::twoFactorTemporarilyDisabled()) {
            $this->redirect($this->postLoginTarget());
        }

        if (Auth::twoFactorEnabled()) {
            $this->redirect(Auth::twoFactorVerified() ? $this->postLoginTarget() : '/2fa/verify');
        }

        $setup = (new TwoFactorService($this->app))->beginSetup((int) Auth::id());

        return $this->view('auth/two_factor_setup', [
            'title' => '2FA Setup',
            'qrSvg' => $setup['qr_svg'],
            'manualSecret' => $setup['secret'],
        ]);
    }

    public function completeTwoFactorSetup(): string
    {
        if (Auth::mustChangePassword()) {
            $this->redirect('/force-password-change');
        }

        if (Auth::twoFactorTemporarilyDisabled()) {
            $this->redirect($this->postLoginTarget());
        }

        Csrf::verifyOrFail($_POST['_token'] ?? null);

        $result = (new TwoFactorService($this->app))->completeSetup(
            (int) Auth::id(),
            (string) ($_POST['otp'] ?? ''),
            (string) ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1')
        );

        if (! $result['success']) {
            Flash::error($result['message']);
            return $this->showTwoFactorSetup();
        }

        $this->redirect('/2fa/recovery-codes');
    }

    public function showTwoFactorVerify(): string
    {
        if (Auth::mustChangePassword()) {
            $this->redirect('/force-password-change');
        }

        if (Auth::twoFactorTemporarilyDisabled()) {
            $this->redirect($this->postLoginTarget());
        }

        if (! Auth::twoFactorEnabled()) {
            $this->redirect('/2fa/setup');
        }

        if (Auth::twoFactorVerified()) {
            $this->redirect($this->postLoginTarget());
        }

        return $this->view('auth/two_factor_verify', [
            'title' => '2FA Verification',
        ]);
    }

    public function verifyTwoFactor(): string
    {
        if (Auth::mustChangePassword()) {
            $this->redirect('/force-password-change');
        }

        if (Auth::twoFactorTemporarilyDisabled()) {
            $this->redirect($this->postLoginTarget());
        }

        Csrf::verifyOrFail($_POST['_token'] ?? null);

        $result = (new TwoFactorService($this->app))->verifyChallenge(
            (int) Auth::id(),
            (string) ($_POST['code'] ?? ''),
            (string) ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'),
            isset($_POST['remember_device']) && $_POST['remember_device'] === '1'
        );

        if (! $result['success']) {
            Flash::error($result['message']);
            return $this->showTwoFactorVerify();
        }

        $this->redirect($this->postLoginTarget());
    }

    public function showRecoveryCodes(): string
    {
        $codes = (new TwoFactorService($this->app))->consumeBackupCodesForDisplay();

        if ($codes === []) {
            $this->redirect($this->postLoginTarget());
        }

        return $this->view('auth/two_factor_recovery_codes', [
            'title' => 'Recovery Codes',
            'codes' => $codes,
        ]);
    }

    public function login(): string
    {
        Csrf::verifyOrFail($_POST['_token'] ?? null);

        $login = trim((string) ($_POST['login'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $ipAddress = (string) ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
        $result = (new AuthService($this->app))->attempt($login, $password, $ipAddress);

        if (! $result['success']) {
            Flash::error($result['message']);
            return $this->showLogin();
        }

        $this->redirect($result['redirect_to']);
    }

    public function logout(): never
    {
        \App\Helpers\AuditLog::record($this->app, 'auth.logout', [
            'user_id' => Auth::id(),
        ]);
        Auth::logout();
        $this->redirect('/login');
    }
}
