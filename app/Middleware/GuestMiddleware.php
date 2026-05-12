<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Helpers\Auth;

final class GuestMiddleware extends Middleware
{
    public function handle(): void
    {
        if (Auth::check()) {
            $target = Auth::mustChangePassword()
                ? '/force-password-change'
                : (Auth::twoFactorTemporarilyDisabled()
                    ? (Auth::isSuperAdmin() ? '/' : '/workspace')
                    : (Auth::twoFactorEnabled()
                        ? (Auth::twoFactorVerified() ? (Auth::isSuperAdmin() ? '/' : '/workspace') : '/2fa/verify')
                        : '/2fa/setup'));

            header('Location: ' . url($target));
            exit;
        }
    }
}
