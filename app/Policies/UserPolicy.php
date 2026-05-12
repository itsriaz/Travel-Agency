<?php

declare(strict_types=1);

namespace App\Policies;

final class UserPolicy extends Policy
{
    public function accessDashboard(array $user): bool
    {
        return in_array($user['role_code'] ?? '', ['super_admin', 'branch_user'], true);
    }
}
