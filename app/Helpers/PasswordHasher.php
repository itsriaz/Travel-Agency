<?php

declare(strict_types=1);

namespace App\Helpers;

final class PasswordHasher
{
    public static function make(string $password): string
    {
        return password_hash($password, self::algorithm(), self::options());
    }

    public static function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, self::algorithm(), self::options());
    }

    private static function algorithm(): string|int|null
    {
        $configured = strtolower(trim((string) config('security.password.hash_driver', 'argon2id')));

        return match ($configured) {
            'argon2id' => defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT,
            'argon2i' => defined('PASSWORD_ARGON2I') ? PASSWORD_ARGON2I : PASSWORD_DEFAULT,
            'bcrypt' => PASSWORD_BCRYPT,
            default => PASSWORD_DEFAULT,
        };
    }

    private static function options(): array
    {
        $configured = strtolower(trim((string) config('security.password.hash_driver', 'argon2id')));

        return match ($configured) {
            'argon2id', 'argon2i' => [
                'memory_cost' => max(32768, (int) config('security.password.argon.memory_cost', 65536)),
                'time_cost' => max(2, (int) config('security.password.argon.time_cost', 4)),
                'threads' => max(1, (int) config('security.password.argon.threads', 2)),
            ],
            'bcrypt' => [
                'cost' => max(10, (int) config('security.password.bcrypt.cost', 12)),
            ],
            default => [],
        };
    }
}
