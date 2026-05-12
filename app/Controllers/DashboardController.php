<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Authorization;
use App\Helpers\Auth;
use App\Services\ReportService;
use RuntimeException;

final class DashboardController extends BaseController
{
    public function index(): string
    {
        $user = Auth::user();

        if ($user === null) {
            $this->redirect('/login');
        }

        $targetView = ($user['roleCode'] ?? $user['role_code'] ?? '') === 'super_admin'
            ? 'dashboard/index'
            : 'workspace/index';

        $dashboardAnalytics = null;
        if ($targetView === 'dashboard/index') {
            try {
                $dashboardAnalytics = (new ReportService($this->app))->adminNetProfitDashboardState(
                    Authorization::accessibleBranchIds(),
                    (int) Auth::id()
                );
            } catch (RuntimeException) {
                $dashboardAnalytics = null;
            }
        }

        return $this->view($targetView, [
            'title' => $targetView === 'dashboard/index' ? 'Dashboard' : 'Booking Workspace',
            'user' => $user,
            'dashboardAnalytics' => $dashboardAnalytics,
        ]);
    }
}
