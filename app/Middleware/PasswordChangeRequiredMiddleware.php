<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Helpers\Auth;

final class PasswordChangeRequiredMiddleware extends Middleware
{
    public function handle(): void
    {
        if (! Auth::mustChangePassword()) {
            return;
        }

        header('Location: ' . url('/force-password-change'));
        exit;
    }
}
