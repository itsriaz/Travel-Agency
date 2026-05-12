<?php

declare(strict_types=1);

namespace App\Helpers;

final class Auth
{
    public static function user(): ?array
    {
        $user = Session::get('auth_user');

        return is_array($user) ? $user : null;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function id(): ?int
    {
        return self::user()['id'] ?? null;
    }

    public static function roleCode(): ?string
    {
        $user = self::user();

        return $user['roleCode'] ?? $user['role_code'] ?? null;
    }

    public static function isSuperAdmin(): bool
    {
        return self::roleCode() === 'super_admin';
    }

    public static function activeBranchId(): ?int
    {
        $user = self::user();

        return $user['activeBranchId'] ?? $user['active_branch_id'] ?? null;
    }

    public static function mustChangePassword(): bool
    {
        $user = self::user();

        return (bool) ($user['mustChangePassword'] ?? $user['must_change_password'] ?? false);
    }

    public static function sessionVersion(): int
    {
        $user = self::user();

        return (int) ($user['sessionVersion'] ?? $user['session_version'] ?? 1);
    }

    public static function twoFactorTemporarilyDisabled(): bool
    {
        return (bool) config('security.two_factor.temporarily_disabled', false);
    }

    public static function twoFactorEnabled(): bool
    {
        if (self::twoFactorTemporarilyDisabled()) {
            return false;
        }

        $user = self::user();

        return (bool) ($user['twoFactorEnabled'] ?? $user['two_factor_enabled'] ?? false);
    }

    public static function twoFactorVerified(): bool
    {
        $user = self::user();

        return (bool) ($user['twoFactorVerified'] ?? $user['two_factor_verified'] ?? false);
    }

    public static function login(array $user): void
    {
        Session::regenerate();
        Session::put('auth_user', $user);
        Session::touchAuthActivity();
    }

    public static function refresh(array $user, bool $rotateSessionId = false): void
    {
        if ($rotateSessionId) {
            Session::regenerate();
        }

        Session::put('auth_user', $user);
        Session::touchAuthActivity();
    }

    public static function logout(): void
    {
        Session::forget('auth_user');
        Session::forget('_auth_last_activity');
        Session::destroy();
    }
}
