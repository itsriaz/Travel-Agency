<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Helpers\Auth;

final class TwoFactorSetupRequiredMiddleware extends Middleware
{
    public function handle(): void
    {
        if (Auth::mustChangePassword() || Auth::twoFactorTemporarilyDisabled()) {
            return;
        }

        if (Auth::twoFactorEnabled()) {
            return;
        }

        header('Location: ' . url('/2fa/setup'));
        exit;
    }
}
