<?php

declare(strict_types=1);

namespace App\Helpers;

final class Authorization
{
    public static function accessibleBranchIds(): array
    {
        $user = Auth::user();

        if ($user === null) {
            return [];
        }

        if (Auth::isSuperAdmin()) {
            return $user['accessibleBranchIds'] ?? $user['accessible_branch_ids'] ?? [];
        }

        $activeBranchId = (int) ($user['activeBranchId'] ?? $user['active_branch_id'] ?? 0);

        return $activeBranchId > 0 ? [$activeBranchId] : [];
    }

    public static function canAccessBranch(int $branchId): bool
    {
        if (Auth::isSuperAdmin()) {
            return true;
        }

        return in_array($branchId, self::accessibleBranchIds(), true);
    }
}
