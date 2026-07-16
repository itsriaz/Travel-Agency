<?php

declare(strict_types=1);

namespace App\Helpers;

use RuntimeException;
use Throwable;

final class PasswordHasher
{
    public static function make(string $password): string
    {
        [$algorithm, $options] = self::configuredHashProfile();

        try {
            $hash = password_hash($password, $algorithm, $options);
            if (is_string($hash) && $hash !== '') {
                return $hash;
            }
        } catch (Throwable) {
        }

        $fallback = password_hash($password, PASSWORD_BCRYPT, [
            'cost' => max(10, (int) config('security.password.bcrypt.cost', 12)),
        ]);

        if (is_string($fallback) && $fallback !== '') {
            return $fallback;
        }

        throw new RuntimeException('Password hashing is unavailable on this server.');
    }

    public static function needsRehash(string $hash): bool
    {
        [$algorithm, $options] = self::configuredHashProfile();

        try {
            return password_needs_rehash($hash, $algorithm, $options);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return array{0:string|int|null,1:array<string,int>}
     */
    private static function configuredHashProfile(): array
    {
        $configured = strtolower(trim((string) config('security.password.hash_driver', 'argon2id')));

        $algorithm = match ($configured) {
            'argon2id' => defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT,
            'argon2i' => defined('PASSWORD_ARGON2I') ? PASSWORD_ARGON2I : PASSWORD_DEFAULT,
            'bcrypt' => PASSWORD_BCRYPT,
            default => PASSWORD_DEFAULT,
        };

        $options = match ($configured) {
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

        if ($algorithm === PASSWORD_BCRYPT) {
            $options = [
                'cost' => max(10, (int) config('security.password.bcrypt.cost', 12)),
            ];
        }

        if ($algorithm === PASSWORD_DEFAULT && $configured !== 'bcrypt') {
            $options = [];
        }

        return [$algorithm, $options];
    }
}
