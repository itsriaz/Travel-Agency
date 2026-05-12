<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Helpers\Auth;

final class TwoFactorVerifiedMiddleware extends Middleware
{
    public function handle(): void
    {
        if (Auth::mustChangePassword() || ! Auth::twoFactorEnabled()) {
            return;
        }

        if (Auth::twoFactorVerified()) {
            return;
        }

        header('Location: ' . url('/2fa/verify'));
        exit;
    }
}
