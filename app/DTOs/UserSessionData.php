<?php

declare(strict_types=1);

namespace App\DTOs;

final class UserSessionData extends DataTransferObject
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $username,
        public readonly string $email,
        public readonly string $roleCode,
        public readonly int $activeBranchId,
        public readonly array $accessibleBranchIds,
        public readonly bool $mustChangePassword,
        public readonly int $sessionVersion,
        public readonly bool $twoFactorEnabled,
        public readonly bool $twoFactorVerified
    ) {
    }
}
