<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Helpers\Auth;
use App\Helpers\AuditLog;
use App\Helpers\Session;
use App\Repositories\UserRepository;

final class AuthMiddleware extends Middleware
{
    public function handle(): void
    {
        if (! Auth::check()) {
            header('Location: ' . url('/login'));
            exit;
        }

        $lastActivity = Session::lastAuthActivity();
        $timeoutSeconds = (int) config('security.session.timeout_minutes', 30) * 60;

        if ($lastActivity !== null && (time() - $lastActivity) > $timeoutSeconds) {
            AuditLog::record($this->app, 'auth.session.timeout', [
                'user_id' => Auth::id(),
            ]);
            Auth::logout();
            header('Location: ' . url('/login'));
            exit;
        }

        $userState = (new UserRepository($this->app))->currentAuthState((int) Auth::id());

        if ($userState === null || (int) $userState['is_active'] !== 1) {
            Auth::logout();
            header('Location: ' . url('/login'));
            exit;
        }

        if ((int) $userState['session_version'] !== Auth::sessionVersion()) {
            AuditLog::record($this->app, 'auth.session.invalidated', [
                'user_id' => Auth::id(),
            ]);
            Auth::logout();
            header('Location: ' . url('/login'));
            exit;
        }

        Session::touchAuthActivity();
    }
}
