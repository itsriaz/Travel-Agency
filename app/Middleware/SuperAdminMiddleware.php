<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Helpers\Auth;

final class SuperAdminMiddleware extends Middleware
{
    public function handle(): void
    {
        if (! Auth::isSuperAdmin()) {
            http_response_code(403);
            echo \App\Core\View::make($this->app->basePath('/app/Views/errors/403.php'), ['title' => 'Forbidden']);
            exit;
        }
    }
}
