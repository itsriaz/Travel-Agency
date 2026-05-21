<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Helpers\Auth;

final class FinancialAdminMiddleware extends Middleware
{
    public function handle(): void
    {
        if (! in_array(Auth::roleCode(), ['super_admin', 'branch_admin'], true)) {
            http_response_code(403);
            echo \App\Core\View::make($this->app->basePath('/app/Views/errors/403.php'), ['title' => 'Forbidden']);
            exit;
        }
    }
}
